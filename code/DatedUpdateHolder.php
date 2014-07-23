<?php
/**
 * An abstract base class for {@link NewsHolder} pages.
 *
 * This code is forked from https://gitlab.cwp.govt.nz/cwp/cwp (16/Jul/2014)
 *
 * This class provides the rss and filtering features for displaying {@link DatedUpdatePage} pages by month, year and taxonomy tag.
 *
 * Requires the taxonomy module, note the var $taxonomy_join_table which must match your many_many array key for
 * the {@link TaxonomyTerm} relationship in the base page where your taxonomy is setup, e.g. if you did that in Page.php and had:
 *
 * 	private static $many_many = array(
 *	  'Tags' => 'TaxonomyTerm'
 *	);
 *
 * then you'd need $taxonomy_join_table = 'Page_Tags'; as that's the join table name between Page and Tags, yo.
 *
 * @author  scienceninjas@silverstripe.com
 * @license BSD License (http://silverstripe.org/bsd-license/)
 * @package datedupdates
 */
class DatedUpdateHolder extends Page {

	/**
	 * @var $hide_ancestor This is meant as an abstract base class, so we want to hide the ancestor from the cms.
	 */
	private static $hide_ancestor = 'DatedUpdateHolder';

	/**
	 * @var $update_name A display label used in the getUpdateName function.
	 */
	private static $update_name = 'Updates';

	/**
	 * @var $update_class The child page classname to lookup when finding all updates belonging to this holder.
	 */
	private static $update_class = 'DatedUpdatePage';

	/**
	 * @var $taxonomy_join_table This must match your many_many array key for the {@link TaxonomyTerm} relationship in the base page where your taxonomy is setup.
	 */
	private static $taxonomy_join_table = 'Page_Tags';

	/**
	 * Find all distinct tags (TaxonomyTerms) associated with the DatedUpdatePages under this holder.
	 *
	 * @return DataList
	 */
	public function UpdateTags() {
		$tags = TaxonomyTerm::get()
			->innerJoin(self::$taxonomy_join_table, '"TaxonomyTerm"."ID"="'.self::$taxonomy_join_table.'"."TaxonomyTermID"')
			->innerJoin('SiteTree', '"SiteTree"."ID"="'.self::$taxonomy_join_table.'"."PageID" AND "SiteTree"."ParentID"='.$this->ID)
			->sort('Name');

		return $tags;
	}

	/**
	 * Wrapper to find all updates belonging to this holder, based on some filters.
	 *
	 * @return DataList | PaginatedList
	 */
	public function Updates($tagID = null, $dateFrom = null, $dateTo = null, $year = null, $monthNumber = null) {
		$className = Config::inst()->get($this->ClassName, 'update_class');
		return static::AllUpdates($className, $this->ID, $tagID, $dateFrom, $dateTo, $year, $monthNumber);
	}

	/**
	 * Find all the sites updates, based on some filters.
	 * Omitting parameters will prevent relevant filters from being applied. The filters are ANDed together.
	 *
	 * @param $className The name of the class to fetch.
	 * @param $parentID The ID of the holder to extract the updates from.
	 * @param $tagID The ID of the tag to filter the updates by.
	 * @param $dateFrom The beginning of a date filter range.
	 * @param $dateTo The end of the date filter range. If empty, only one day will be searched for.
	 * @param $year Numeric value of the year to show.
	 * @param $monthNumber Numeric value of the month to show.
	 *
	 * @return DataList | PaginatedList
	 */
	public static function AllUpdates($className = 'DatedUpdatePage', $parentID = null, $tagID = null, $dateFrom = null,
			$dateTo = null, $year = null, $monthNumber = null) {

		$items = $className::get();

		// Filter by parent holder.
		if (isset($parentID)) {
			$items = $items->filter(array('ParentID'=>$parentID));
		}

		// Filter down to a single tag.
		if (isset($tagID)) {
			$items = $items
				->innerJoin(self::$taxonomy_join_table, '"DatedUpdatePage"."ID"="'.self::$taxonomy_join_table.'"."PageID"')
				->innerJoin('TaxonomyTerm', '"'.self::$taxonomy_join_table.'"."TaxonomyTermID"="TaxonomyTerm"."ID" AND "TaxonomyTerm"."ID"='.Convert::raw2sql($tagID));
		}

		// Filter by date
		if (isset($dateFrom)) {

			if (isset($dateTo)) {
				// Date range
				$dateTo = "$dateTo 23:59:59";
				$dateFrom = "$dateFrom 00:00:00";
			}
			else {
				// Single date, set the dateTo based on the dateFrom.
				$dateTo = "$dateFrom 23:59:59";
				$dateFrom = "$dateFrom 00:00:00";
			}

			$items = $items->where('("DatedUpdatePage"."Date">=\''.$dateFrom.'\' AND "DatedUpdatePage"."Date"<=\''.$dateTo.'\')');
		}

		// Filter down to single month.
		if (isset($year) && isset($monthNumber)) {
			$year = (int)$year;
			$monthNumber = (int)$monthNumber;

			$beginDate = "$year-$monthNumber-01 00:00:00";
			$endDate = date('Y-m-d H:i:s', strtotime("$year-$monthNumber-1 00:00:00 +1 month"));

			$items = $items->where('("DatedUpdatePage"."Date">=\''.$beginDate.'\' AND "DatedUpdatePage"."Date"<\''.$endDate.'\')');
		}

		// Unpaginated DataList.
		return $items;
	}

	/**
	 * Produce an ArrayList of available months out of the updates contained in the DataList.
	 *
	 * Here is an example of the returned structure:
	 * ArrayList:
	 *   ArrayData:
	 *     YearName => 2013
	 *     Months => ArrayList:
	 *       MonthName => Jan
	 *       MonthNumber => 1
	 *       MonthLink => (page URL)year=2012&month=1
	 *       Active => true
	 *   ArrayData:
	 *     YearName => 2012
	 *     Months => ArrayList:
	 *     ...
	 *
	 * @param DataList $updates The DataList to extract months from
	 * @param $link Link used as abase to construct the MonthLink
	 * @param $currentYear Currently selected year, for computing the link active state
	 * @param $currentMonthNumber Currently selected month, for computing the link active state
	 *
	 * @return ArrayList
	 */
	public static function ExtractMonths(DataList $updates, $link = null, $currentYear = null, $currentMonthNumber = null) {
		// Set the link to current URL in the same way the HTTP::setGetVar does it.
		if (!isset($link)) {
			$link = Director::makeRelative($_SERVER['REQUEST_URI']);
		}

		$updates = $updates->Sort('Date', 'ASC');

		$years = array();
		foreach ($updates as $update) {
			$date = $update->obj('Date');
			$year = $date->Format('Y');
			// Check if the date is actually set
			if ($year) {
				$monthNumber = $date->Format('n');
				$monthName = $date->Format('M');

				// Set up the relevant year array, if not yet available.
				if (!isset($years[$year])) {
					$years[$year] = array('YearName'=>$year, 'Months'=>array());
				}

				// Check if the currently processed month is the one that is selected via GET params.
				$active = false;
				if (isset($year) && isset($monthNumber)) {
					$active = (((int)$currentYear)==$year && ((int)$currentMonthNumber)==$monthNumber);
				}

				// Build the link - keep the tag and date filter, but reset the pagination.
				if ($active) {
					// Allow clicking to deselect the month.
					$link = HTTP::setGetVar('month', null, $link, '&');
					$link = HTTP::setGetVar('year', null, $link, '&');
				} else {
					$link = HTTP::setGetVar('month', $monthNumber, $link, '&');
					$link = HTTP::setGetVar('year', $year, $link, '&');
				}
				$link = HTTP::setGetVar('start', null, $link, '&');

				$years[$year]['Months'][$monthNumber] = array(
					'MonthName'=>$monthName,
					'MonthNumber'=>$monthNumber,
					'MonthLink'=>$link,
					'Active'=>$active
				);
			}
		}

		// ArrayList will not recursively walk through the supplied array, so manually build nested ArrayLists.
		foreach ($years as &$year) {
			$year['Months'] = new ArrayList($year['Months']);
		}

		// Reverse the list so the most recent years appear first.
		return new ArrayList(array_reverse($years));
	}

	/**
	 * @return string
	 */
	public function getDefaultRSSLink() {
		return $this->Link('rss');
	}

	/**
	 * @return string
	 */
	public function getSubscriptionTitle() {
		return $this->Title;
	}
}

/**
 * The parameters apply in the following preference order:
 *  - Highest priority: Tag & date (or date range)
 *  - Month (and Year)
 *  - Pagination
 *
 * So, when the user click on a tag link, the pagination, and month will be reset, but not the date filter. Also,
 * changing the date will not affect the tag, but will reset the month and pagination.
 *
 * When the user clicks on a month, pagination will be reset, but tags retained. Pagination retains all other
 * parameters.
 */
class DatedUpdateHolder_Controller extends Page_Controller {

	/**
	 * @var array The list of functions that are public scoped url segments in this controller
	 */
	private static $allowed_actions = array(
		'rss',
		'DateRangeForm'
	);

	/**
	 * Initialise the controller to include requirements and sort out the rss feed
	 */
	public function init() {
		parent::init();

		// Include the DateRangeForm JS manually. We use custom form and $DateRangeForm is never invoked directly.
		Requirements::javascript('framework/javascript/DateField.js');
		Requirements::css('framework/thirdparty/jquery-ui-themes/smoothness/jquery-ui.css');

		RSSFeed::linkToFeed($this->Link() . 'rss', $this->getSubscriptionTitle());
	}

	/**
	 * Get a description of the current filter
	 *
	 * @return string
	 */
	public function FilterDescription() {
		$params = $this->parseParams();

		$filters = array();
		if ($params['tag']) {
			$term = TaxonomyTerm::get_by_id('TaxonomyTerm', $params['tag']);
			if ($term) {
				$filters[] = 'within "' . $term->Name . '"';
			}
		}

		if ($params['from'] || $params['to']) {
			if ($params['from']) {
				$from = strtotime($params['from']);
				if ($params['to']) {
					$to = strtotime($params['to']);
					$filters[] = 'between ' . date('j/m/Y', $from) . ' and ' . date('j/m/Y', $to);
				} else {
					$filters[] = 'on ' . date('j/m/Y', $from);
				}
			} else {
				$to = strtotime($params['to']);
				$filters[] = 'on ' . date('j/m/Y', $to);
			}
		}

		if ($params['year'] && $params['month']) {
			$timestamp = mktime(1, 1, 1, $params['month'], 1, $params['year']);
			$filters[] = 'in ' . date('F', $timestamp) . ' ' . $params['year'];
		}

		if ($filters) {
			return $this->getUpdateName() . ' ' . implode(' ', $filters);
		}
	}

	/**
	 * Get a label to use in the Updates display
	 *
	 * @return string
	 */
	public function getUpdateName() {
		return Config::inst()->get($this->data()->ClassName, 'update_name');
	}

	/**
	 * Parse URL parameters and set the filters
	 *
	 * @param boolean $produceErrorMessages Set to false to omit session messages.
	 * @return array
	 */
	public function parseParams($produceErrorMessages = true) {
		$tag = $this->request->getVar('tag');
		$from = $this->request->getVar('from');
		$to = $this->request->getVar('to');
		$year = $this->request->getVar('year');
		$month = $this->request->getVar('month');

		if ($tag=='') $tag = null;
		if ($from=='') $from = null;
		if ($to=='') $to = null;
		if ($year=='') $year = null;
		if ($month=='') $month = null;

		if (isset($tag)) $tag = (int)$tag;
		if (isset($from)) {
			$from = urldecode($from);
			$parser = new SS_Datetime;
			$parser->setValue($from);
			$from = $parser->Format('Y-m-d');
		}
		if (isset($to)) {
			$to = urldecode($to);
			$parser = new SS_Datetime;
			$parser->setValue($to);
			$to = $parser->Format('Y-m-d');
		}
		if (isset($year)) $year = (int)$year;
		if (isset($month)) $month = (int)$month;

		// If only "To" has been provided filter by single date. Normalise by swapping with "From".
		if (isset($to) && !isset($from)) {
			list($to, $from) = array($from, $to);
		}

		// Flip the dates if the order is wrong.
		if (isset($to) && isset($from) && strtotime($from)>strtotime($to)) {
			list($to, $from) = array($from, $to);

			if ($produceErrorMessages) {
				Session::setFormMessage(
					'Form_DateRangeForm',
					_t('DateUpdateHolder.FilterAppliedMessage','Filter has been applied with the dates reversed.'),
					'warning'
				);
			}
		}

		// Notify the user that filtering by single date is taking place.
		if (isset($from) && !isset($to)) {
			if ($produceErrorMessages) {
				Session::setFormMessage(
					'Form_DateRangeForm',
					_t('DateUpdateHolder.DateRangeFilterMessage','Filtered by a single date.'),
					'warning'
				);
			}
		}

		return array(
			'tag' => $tag,
			'from' => $from,
			'to' => $to,
			'year' => $year,
			'month' => $month
		);
	}

	/**
	 * Build the link - keep the date range, reset the rest.
	 *
	 * @return string Absolute URL
	 */
	public function AllTagsLink() {
		$link = HTTP::setGetVar('tag', null, null, '&');
		$link = HTTP::setGetVar('month', null, $link, '&');
		$link = HTTP::setGetVar('year', null, $link, '&');
		$link = HTTP::setGetVar('start', null, $link, '&');

		return $link;
	}

	/**
	 * List tags and attach links.
	 *
	 * @return ArrayList
	 */
	public function UpdateTagsWithLinks() {
		$tags = $this->UpdateTags();

		$processed = new ArrayList();

		foreach ($tags as $tag) {
			// Build the link - keep the tag, and date range, but reset month, year and pagination
			$link = HTTP::setGetVar('tag', $tag->ID, null, '&');
			$link = HTTP::setGetVar('month', null, $link, '&');
			$link = HTTP::setGetVar('year', null, $link, '&');
			$link = HTTP::setGetVar('start', null, $link, '&');

			$tag->Link = $link;
			$processed->push($tag);
		}

		return $processed;
	}

	/**
	 * Get the TaxonomyTerm related to the current tag GET parameter.
	 *
	 * @return TaxonomyTerm
	 */
	public function CurrentTag() {
		$tagID = $this->request->getVar('tag');

		if (isset($tagID)) {
			return TaxonomyTerm::get_by_id('TaxonomyTerm', (int)$tagID);
		}
	}

	/**
	 * Extract the available months based on the current query.
	 * Only tag is respected. Pagination and months are ignored.
	 *
	 * @return DatedUpdateHolder
	 */
	public function AvailableMonths() {
		$params = $this->parseParams();

		return DatedUpdateHolder::ExtractMonths(
			$this->Updates($params['tag'], $params['from'], $params['to']),
			Director::makeRelative($_SERVER['REQUEST_URI']),
			$params['year'],
			$params['month']
		);
	}

	/**
	 * Get the updates based on the current query.
	 *
	 * @return PaginatedList
	 */
	public function FilteredUpdates($pageSize = 20) {
		$params = $this->parseParams();

		$items = $this->Updates(
			$params['tag'],
			$params['from'],
			$params['to'],
			$params['year'],
			$params['month']
		);

		// Apply pagination
		$list = new PaginatedList($items, $this->request);
		$list->setPageLength($pageSize);
		return $list;
	}

	/**
	 * @return Form
	 */
	public function DateRangeForm() {
		$fields = new FieldList(
			$dateFrom = new DateField('from'),
			$dateTo = new DateField('to'),
			new HiddenField('tag')
		);
		$dateFrom->setConfig('showcalendar', true);
		$dateTo->setConfig('showcalendar', true);

		$actions = new FieldList(
			FormAction::create("doDateFilter")->setTitle("Filter")->addExtraClass('btn btn-primary primary'),
			FormAction::create("doDateReset")->setTitle("Clear")->addExtraClass('btn')
		);

		$form = new Form($this, 'DateRangeForm', $fields, $actions);
		$form->loadDataFrom($this->request->getVars());
		$form->setFormMethod('get');

		// Manually extract the message so we can clear it.
		$form->ErrorMessage = $form->Message();
		$form->ErrorMessageType = $form->MessageType();
		$form->clearMessage();

		return $form;
	}

	/**
	 * @return void
	 */
	public function doDateFilter() {
		$params = $this->parseParams();

		// Build the link - keep the tag, but reset month, year and pagination.
		$link = HTTP::setGetVar('from', $params['from'], $this->AbsoluteLink(), '&');
		$link = HTTP::setGetVar('to', $params['to'], $link, '&');
		if (isset($params['tag'])) $link = HTTP::setGetVar('tag', $params['tag'], $link, '&');

		$this->redirect($link);
	}

	/**
	 * @return void
	 */
	public function doDateReset() {
		$params = $this->parseParams(false);

		// Reset the link - only include the tag.
		$link = $this->AbsoluteLink();
		if (isset($params['tag'])) $link = HTTP::setGetVar('tag', $params['tag'], $link, '&');

		$this->redirect($link);
	}

	/**
	 * @return string
	 */
	public function rss() {
		$rss = new RSSFeed($this->Updates()->sort('Created DESC')->limit(20), $this->Link(), $this->getSubscriptionTitle());
		return $rss->outputToBrowser();
	}
}
