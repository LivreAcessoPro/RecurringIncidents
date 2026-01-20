<?php declare(strict_types = 0);
/*
** Copyright (C) 2001-2025 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


namespace Modules\RecurringIncidents\Includes;

use CButtonIcon,
	CCol,
	CColHeader,
	CHintBoxHelper,
	CDiv,
	CIcon,
	CLink,
	CLinkAction,
	CMacrosResolverHelper,
	CMenuPopupHelper,
	CRow,
	CScreenProblem,
	CSeverityHelper,
	CSpan,
	CTag,
	CTable,
	CTableInfo,
	CSlaHelper,
	CUrl;

class WidgetRecurringIncidents extends CTableInfo {
	private array $data;

	public function __construct(array $data) {
		$this->data = $data;

		parent::__construct();
	}

	private function build(): void {
		$sort_div = (new CSpan())->addClass(
			($this->data['sortorder'] === ZBX_SORT_DOWN) ? ZBX_STYLE_ARROW_DOWN : ZBX_STYLE_ARROW_UP
		);

		$show_timeline = ($this->data['sortfield'] === 'clock' && $this->data['fields']['show_timeline']);

		$header_time = (new CColHeader(($this->data['sortfield'] === 'clock')
			? [_x('Time', 'compact table header'), $sort_div]
			: _x('Time', 'compact table header')))->addStyle('width: 120px;');

		$header = [];

		if ($show_timeline) {
			$header[] = $header_time->addClass(ZBX_STYLE_RIGHT);
			$header[] = (new CColHeader())->addClass(ZBX_STYLE_TIMELINE_TH);
			$header[] = (new CColHeader())->addClass(ZBX_STYLE_TIMELINE_TH);
		}
		else {
			$header[] = $header_time;
		}

		$this->setHeader(array_merge($header, [
			_x('Recurrences', 'compact table header'),
			_x('Trend', 'compact table header'),
			_x('MTTR', 'compact table header'),
			_x('SLI', 'compact table header'),
			_x('Info', 'compact table header'),
			($this->data['sortfield'] === 'host')
				? [_x('Host', 'compact table header'), $sort_div]
				: _x('Host', 'compact table header'),
			[
				($this->data['sortfield'] === 'name')
					? [_x('Problem', 'compact table header'), $sort_div]
					: _x('Problem', 'compact table header'),
				' ', BULLET(), ' ',
				($this->data['sortfield'] === 'severity')
					? [_x('Severity', 'compact table header'), $sort_div]
					: _x('Severity', 'compact table header')
			],
			_x('Duration', 'compact table header'),
			_('Update'),
			_x('Actions', 'compact table header'),
			$this->data['fields']['show_tags'] ? _x('Tags', 'compact table header') : null
		]));

		$this->data['triggers_hosts'] = $this->data['problems']
			? makeTriggersHostsList($this->data['triggers_hosts'] ?? [])
			: [];

		$this->data += [
			'today' => strtotime('today'),
			'show_timeline' => $show_timeline,
			'last_clock' => 0,
			'show_three_columns' => false,
			'show_two_columns' => false,
			'show_recovery_data' => false
		];

		$this->addProblemsToTable($this->data['problems'], $this->data);

		if ($this->data['info'] !== '') {
			$this->setFooter([
				(new CCol($this->data['info']))
					->setColSpan($this->getNumCols())
					->addClass(ZBX_STYLE_LIST_TABLE_FOOTER)
			]);
		}
	}

	/**
	 * Add recurring problems to table.
	 */
	private function addProblemsToTable(array $problems, array $data): void {
		foreach ($problems as $problem) {
			$trigger = $data['triggers'][$problem['objectid']];

			$cell_clock = ($problem['clock'] >= $data['today'])
				? zbx_date2str(TIME_FORMAT_SECONDS, $problem['clock'])
				: zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']);

			if ($data['allowed']['ui_problems']) {
				$cell_clock = new CCol(new CLink($cell_clock,
					(new CUrl('tr_events.php'))
						->setArgument('triggerid', $problem['objectid'])
						->setArgument('eventid', $problem['eventid'])
				));
			}
			else {
				$cell_clock = new CCol($cell_clock);
			}

			$value = TRIGGER_VALUE_TRUE;
			$value_clock = $problem['clock'];
			$is_acknowledged = ($problem['acknowledged'] == EVENT_ACKNOWLEDGED);
			$cell_status = new CSpan(getEventStatusString(false, $problem));

			if (isEventUpdating(false, $problem)) {
				$cell_status->addClass('js-blink');
			}

			addTriggerValueStyle($cell_status, $value, $value_clock, $is_acknowledged);

			// Info.
			$info_icons = [];
			$info_icons[] = getEventStatusUpdateIcon($problem);

			// MTBF alert: show "time remaining to MTBF" or "exceeded" based on time since last occurrence.
			if (array_key_exists('mtbf_avg', $problem) && $problem['mtbf_avg'] !== null
					&& array_key_exists('last_occurrence', $problem) && $problem['last_occurrence'] !== null) {
				$mtbf_avg = (int) $problem['mtbf_avg'];
				$last_occurrence = (int) $problem['last_occurrence'];
				$since_last = time() - $last_occurrence;
				$remaining = $mtbf_avg - $since_last;

				if ($remaining > 0) {
					$info_icons[] = makeWarningIcon(_s('MTBF: %1$s. %2$s remaining to reach MTBF.',
						convertUnitsS($mtbf_avg),
						convertUnitsS($remaining)
					));
				}
				else {
					$info_icons[] = makeInformationIcon(_s('MTBF: %1$s. MTBF exceeded by %2$s.',
						convertUnitsS($mtbf_avg),
						convertUnitsS(abs($remaining))
					));
				}
			}

			// Recurrence count badge
			$recurrence_count = $problem['recurrence_count'] ?? 0;
			$resolved_count = $problem['resolved_count'] ?? 0;
			$mttr_avg = array_key_exists('mttr_avg', $problem) ? $problem['mttr_avg'] : null;
			$mtbf_avg = array_key_exists('mtbf_avg', $problem) ? $problem['mtbf_avg'] : null;
			$trend_delta = $problem['trend_delta'] ?? 0;

			$recurrence_badge = (new CSpan($recurrence_count))
				->addClass(ZBX_STYLE_ENTITY_COUNT)
				->setAttribute('title',
					_s('Occurrences: %1$d, Resolved: %2$d, MTTR: %3$s, MTBF: %4$s (selected period)',
						$recurrence_count,
						$resolved_count,
						$mttr_avg !== null ? convertUnitsS($mttr_avg) : '—',
						$mtbf_avg !== null ? convertUnitsS($mtbf_avg) : '—'
					)
				);

			$recurrence_col = (new CCol($recurrence_badge))
				->addClass(ZBX_STYLE_NOWRAP);

			$trend_text = ($trend_delta > 0 ? '+' : '').$trend_delta;
			$trend_span = new CSpan($trend_text);

			// For continuous improvement: more incidents is negative, fewer is positive.
			if ($trend_delta > 0) {
				$trend_span->addClass(ZBX_STYLE_COLOR_NEGATIVE);
			}
			elseif ($trend_delta < 0) {
				$trend_span->addClass(ZBX_STYLE_COLOR_POSITIVE);
			}

			$trend_col = (new CCol($trend_span))->addClass(ZBX_STYLE_NOWRAP);

			$mttr_col = (new CCol(
				$mttr_avg !== null ? convertUnitsS($mttr_avg) : '—'
			))->addClass(ZBX_STYLE_NOWRAP);

			$sli_col = new CCol('—');
			if (array_key_exists('sli', $problem) && $problem['sli'] !== null
					&& array_key_exists('slo', $problem) && $problem['slo'] !== null) {
				$sli_tag = CSlaHelper::getSliTag($problem['sli'], (float) $problem['slo']);

				if (array_key_exists('serviceid', $problem) && $problem['serviceid'] !== null
						&& array_key_exists('service_tree', $problem) && is_array($problem['service_tree'])) {
					$sli_tag->setHint(self::makeServiceTreeHint($problem), ZBX_STYLE_HINTBOX_WRAP, true,
						'min-width: 420px; max-width: 680px;'
					);
				}

				$sli_col = new CCol($sli_tag);
			}

			$problem_link = [
				(new CLinkAction($problem['name']))
					->setMenuPopup(CMenuPopupHelper::getTrigger([
						'triggerid' => $trigger['triggerid'],
						'backurl' => (new CUrl('zabbix.php'))
							->setArgument('action', 'dashboard.view')
							->getUrl(),
						'eventid' => $problem['eventid'],
						'show_rank_change_cause' => true
					]))
					->setAttribute('aria-label', _xs('%1$s, Severity, %2$s', 'screen reader',
						$problem['name'], CSeverityHelper::getName((int) $problem['severity'])
					))
			];

			$description = (new CCol($problem_link))->addClass(ZBX_STYLE_WORDBREAK);
			$description_style = CSeverityHelper::getStyle((int) $problem['severity']);

			if ($value == TRIGGER_VALUE_TRUE) {
				$description->addClass($description_style);
			}

			if ((($is_acknowledged && $data['config']['problem_ack_style'])
					|| (!$is_acknowledged && $data['config']['problem_unack_style']))) {
				// blinking
				$duration = time() - $problem['clock'];
				$blink_period = timeUnitToSeconds($data['config']['blink_period']);

				if ($blink_period != 0 && $duration < $blink_period) {
					$description
						->addClass('js-blink')
						->setAttribute('data-time-to-blink', $blink_period - $duration)
						->setAttribute('data-toggle-class', ZBX_STYLE_BLINK_HIDDEN);
				}
			}

			// Create acknowledge link.
			$problem_update_link = ($data['allowed']['add_comments'] || $data['allowed']['change_severity']
					|| $data['allowed']['acknowledge'] || $data['allowed']['suppress_problems']
					|| $data['allowed']['rank_change'])
				? (new CLink(_('Update')))
					->addClass(ZBX_STYLE_LINK_ALT)
					->setAttribute('data-eventid', $problem['eventid'])
					->onClick('acknowledgePopUp({eventids: [this.dataset.eventid]}, this);')
				: new CSpan(_('Update'));

			$row = new CRow();
			if ($data['show_timeline']) {
				if ($data['last_clock'] != 0) {
					CScreenProblem::addTimelineBreakpoint($this, $data, $problem, false, false);
				}
				$data['last_clock'] = $problem['clock'];

				$row->addItem([
					$cell_clock->addClass(ZBX_STYLE_TIMELINE_DATE),
					(new CCol())
						->addClass(ZBX_STYLE_TIMELINE_AXIS)
						->addClass(ZBX_STYLE_TIMELINE_DOT),
					(new CCol())->addClass(ZBX_STYLE_TIMELINE_TD)
				]);
			}
			else {
				$row->addItem($cell_clock
					->addClass(ZBX_STYLE_NOWRAP)
					->addClass(ZBX_STYLE_RIGHT)
				);
			}

			$row
				->addItem([
					$recurrence_col,
					$trend_col,
					$mttr_col,
					$sli_col,
					makeInformationList($info_icons),
					$data['triggers_hosts'][$trigger['triggerid']],
					$description,
					(new CCol(
						(new CLinkAction(zbx_date2age($problem['clock'], 0)))
							->setAjaxHint(CHintBoxHelper::getEventList($trigger['triggerid'], $problem['eventid'],
								false, $data['fields']['show_tags'], $data['fields']['tags'],
								$data['fields']['tag_name_format'], $data['fields']['tag_priority']
							))
					))->addClass(ZBX_STYLE_NOWRAP),
					$problem_update_link,
					makeEventActionsIcons($problem['eventid'], $data['actions'], $data['users'], $is_acknowledged),
					$data['fields']['show_tags'] ? $data['tags'][$problem['eventid']] : null
				])
				->setAttribute('data-eventid', $problem['eventid']);

			$this->addRow($row);
		}
	}

	public function toString($destroy = true): string {
		$this->build();

		return parent::toString($destroy);
	}

	private static function makeServiceTreeHint(array $problem): CTag {
		$serviceid = (int) ($problem['serviceid'] ?? 0);
		$service_name = (string) ($problem['service_name'] ?? '');
		$sla_name = (string) ($problem['sla_name'] ?? '');
		$slo = array_key_exists('slo', $problem) ? (float) $problem['slo'] : null;

		$wrap = (new CDiv())->addClass(ZBX_STYLE_WORDBREAK);

		$wrap->addItem(new CTag('h4', true, _('Service')));

		$table = (new CTable())->addClass(ZBX_STYLE_LIST_TABLE);

		if ($serviceid) {
			$table->addRow([
				_('Service'),
				(new CLink(
					$service_name !== '' ? $service_name : (string) $serviceid,
					(new CUrl('zabbix.php'))
						->setArgument('action', 'service.list')
						->setArgument('serviceid', $serviceid)
						->getUrl()
				))->setTarget('_blank')
			]);
		}

		if ($sla_name !== '' && $slo !== null) {
			$table->addRow([
				_('SLA'),
				_s('%1$s (SLO: %2$s%%)', $sla_name, rtrim(rtrim(sprintf('%.4F', $slo), '0'), '.'))
			]);
		}

		$wrap->addItem($table);

		if (array_key_exists('service_path', $problem) && is_array($problem['service_path']) && $problem['service_path']) {
			$path_names = [];
			foreach ($problem['service_path'] as $node) {
				$path_names[] = $node['name'] ?? $node['serviceid'] ?? '';
			}

			$wrap->addItem(new CTag('h4', true, _('Path')));
			$wrap->addItem(
				(new CTag('div', true, implode(' → ', array_filter($path_names, 'strlen'))))
					->addClass(ZBX_STYLE_WORDBREAK)
			);
		}

		if (array_key_exists('service_tree', $problem) && is_array($problem['service_tree'])
				&& array_key_exists('root', $problem['service_tree']) && is_array($problem['service_tree']['root'])) {
			$wrap->addItem(new CTag('h4', true, _('Tree')));

			$ul = (new CTag('ul', true))->addClass(ZBX_STYLE_LIST_DASHED);
			$ul->addItem(self::renderServiceTreeNode($problem['service_tree']['root']));

			$wrap->addItem(
				(new CDiv($ul))
					->addStyle('max-height: 220px; overflow: auto; padding-top: 4px;')
			);

			if (!empty($problem['service_tree']['truncated'])) {
				$wrap->addItem(makeWarningIcon(_('Tree truncated for performance.')));
			}
		}

		return $wrap;
	}

	private static function renderServiceTreeNode(array $node): CTag {
		$li = new CTag('li', true, $node['name'] ?? (string) ($node['serviceid'] ?? ''));

		if (!empty($node['children'])) {
			$ul = new CTag('ul', true);
			foreach ($node['children'] as $child) {
				if (is_array($child)) {
					$ul->addItem(self::renderServiceTreeNode($child));
				}
			}
			$li->addItem($ul);
		}

		return $li;
	}
}