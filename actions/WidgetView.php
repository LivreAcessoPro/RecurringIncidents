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


namespace Modules\RecurringIncidents\Actions;

use CControllerDashboardWidgetView,
	CControllerResponseData,
	CRoleHelper,
	CScreenProblem,
	CSettingsHelper,
	API;

class WidgetView extends CControllerDashboardWidgetView {

	protected function init(): void {
		parent::init();

		$this->addValidationRules([
			'initial_load' => 'in 0,1'
		]);
	}

	protected function doAction(): void {
		// Editing template dashboard?
		if ($this->isTemplateDashboard() && !$this->fields_values['override_hostid']) {
			$this->setResponse(new CControllerResponseData([
				'name' => $this->getInput('name', $this->widget->getDefaultName()),
				'error' => _('No data.'),
				'user' => [
					'debug_mode' => $this->getDebugMode()
				]
			]));
		}
		else {
			$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);
			$show = $this->fields_values['show'];
			$min_occurrences = $this->fields_values['min_occurrences'] ?? 2;
			$time_from = $this->fields_values['time_period']['from_ts'];
			$time_to = $this->fields_values['time_period']['to_ts'];

			$event_groupids = !$this->isTemplateDashboard() && $this->fields_values['groupids']
				? getSubGroups($this->fields_values['groupids'])
				: null;

			$event_hostids = null;
			if ($this->isTemplateDashboard()) {
				$event_hostids = $this->fields_values['override_hostid'];
			}
			else {
				$event_hostids = $this->fields_values['hostids'] ?: null;
			}

			if (!$this->isTemplateDashboard() && $this->fields_values['exclude_groupids']) {
				$exclude_groupids = getSubGroups($this->fields_values['exclude_groupids']);

				if ($event_hostids === null) {
					if ($event_groupids === null) {
						$event_groupids = array_keys(API::HostGroup()->get([
							'output' => [],
							'with_hosts' => true,
							'preservekeys' => true
						]));
					}

					$event_groupids = array_diff($event_groupids, $exclude_groupids);

					$event_hostids = array_keys(API::Host()->get([
						'output' => [],
						'groupids' => $event_groupids,
						'preservekeys' => true
					]));
				}

				$exclude_hostids = array_keys(API::Host()->get([
					'output' => [],
					'groupids' => $exclude_groupids,
					'preservekeys' => true
				]));

				$event_hostids = array_diff($event_hostids, $exclude_hostids);
			}

			$data = CScreenProblem::getData([
				'show' => $show,
				'from' => $time_from,
				'to' => $time_to,
				'groupids' => !$this->isTemplateDashboard() ? $this->fields_values['groupids'] : null,
				'exclude_groupids' => !$this->isTemplateDashboard() ? $this->fields_values['exclude_groupids'] : null,
				'hostids' => !$this->isTemplateDashboard()
					? $this->fields_values['hostids']
					: $this->fields_values['override_hostid'],
				'name' => $this->fields_values['problem'],
				'severities' => $this->fields_values['severities'],
				'evaltype' => $this->fields_values['evaltype'],
				'tags' => $this->fields_values['tags'],
				'show_symptoms' => false,
				'show_suppressed' => false,
				'acknowledgement_status' => ZBX_ACK_STATUS_ALL,
				'acknowledged_by_me' => 0,
				'show_opdata' => OPERATIONAL_DATA_SHOW_NONE
			], $search_limit);

			$trigger_occurrences = [];
			$recurring_problems = [];

			foreach ($data['problems'] as $problem) {
				$triggerid = $problem['objectid'];
				
				if (!isset($trigger_occurrences[$triggerid])) {
					$trigger_occurrences[$triggerid] = [
						'count' => 0,
						'first_occurrence' => $problem['clock'],
						'last_occurrence' => $problem['clock'],
						'problems' => []
					];
				}

				$trigger_occurrences[$triggerid]['count']++;
				$trigger_occurrences[$triggerid]['first_occurrence'] = min(
					$trigger_occurrences[$triggerid]['first_occurrence'],
					$problem['clock']
				);
				$trigger_occurrences[$triggerid]['last_occurrence'] = max(
					$trigger_occurrences[$triggerid]['last_occurrence'],
					$problem['clock']
				);
				$trigger_occurrences[$triggerid]['problems'][] = $problem;
			}

			$triggerids = array_keys($trigger_occurrences);

			// Accurate recurrence count (not affected by SEARCH_LIMIT).
			$base_event_filter = [
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'value' => TRIGGER_VALUE_TRUE,
				'groupids' => $event_groupids,
				'hostids' => $event_hostids,
				'search' => [
					'name' => ($this->fields_values['problem'] !== '') ? $this->fields_values['problem'] : null
				],
				'trigger_severities' => $this->fields_values['severities'] ?: null,
				'evaltype' => $this->fields_values['evaltype'],
				'tags' => $this->fields_values['tags'] ?: null
			];

			$current_counts_raw = $triggerids
				? API::Event()->get(array_merge($base_event_filter, [
					'countOutput' => true,
					'groupBy' => ['objectid'],
					'objectids' => $triggerids,
					'time_from' => $time_from,
					'time_till' => $time_to
				]))
				: [];

			$current_counts = [];
			foreach ($current_counts_raw as $row) {
				$current_counts[$row['objectid']] = (int) $row['rowscount'];
			}

			$period = max(1, $time_to - $time_from);
			$prev_from = $time_from - $period;
			$prev_to = $time_to - $period;

			$prev_counts_raw = $triggerids
				? API::Event()->get(array_merge($base_event_filter, [
					'countOutput' => true,
					'groupBy' => ['objectid'],
					'objectids' => $triggerids,
					'time_from' => $prev_from,
					'time_till' => $prev_to
				]))
				: [];

			$prev_counts = [];
			foreach ($prev_counts_raw as $row) {
				$prev_counts[$row['objectid']] = (int) $row['rowscount'];
			}

			// Build MTTR/MTBF per trigger based on recovery time (r_clock) and occurrence distribution.
			$events = API::Event()->get([
				'output' => ['eventid', 'r_eventid'],
				'eventids' => array_keys($data['problems']),
				'preservekeys' => true
			]);

			$r_eventids = [];
			foreach ($events as $event) {
				if ($event['r_eventid'] != 0) {
					$r_eventids[$event['r_eventid']] = true;
				}
			}

			$r_events = $r_eventids
				? API::Event()->get([
					'output' => ['eventid', 'clock'],
					'eventids' => array_keys($r_eventids),
					'preservekeys' => true
				])
				: [];

			foreach ($trigger_occurrences as &$occurrence_data) {
				$resolved_sum = 0;
				$resolved_count = 0;
				$clocks = [];

				foreach ($occurrence_data['problems'] as $problem) {
					$clocks[] = (int) $problem['clock'];

					if (array_key_exists($problem['eventid'], $events) && $events[$problem['eventid']]['r_eventid'] != 0) {
						$r_eventid = $events[$problem['eventid']]['r_eventid'];

						if (array_key_exists($r_eventid, $r_events)) {
							$duration = (int) $r_events[$r_eventid]['clock'] - (int) $problem['clock'];
							if ($duration >= 0) {
								$resolved_sum += $duration;
								$resolved_count++;
							}
						}
					}
				}

				sort($clocks);
				$mtbf_avg = null;
				if (count($clocks) >= 2) {
					$diff_sum = 0;
					for ($i = 1; $i < count($clocks); $i++) {
						$diff_sum += $clocks[$i] - $clocks[$i - 1];
					}
					$mtbf_avg = (int) round($diff_sum / (count($clocks) - 1));
				}

				$occurrence_data['resolved_count'] = $resolved_count;
				$occurrence_data['mttr_avg'] = $resolved_count > 0 ? (int) round($resolved_sum / $resolved_count) : null;
				$occurrence_data['mtbf_avg'] = $mtbf_avg;
			}
			unset($occurrence_data);

			// Filter triggers that meet minimum occurrences requirement
			foreach ($trigger_occurrences as $triggerid => $occurrence_data) {
				$accurate_count = $current_counts[$triggerid] ?? 0;

				if ($accurate_count >= $min_occurrences) {
					// Use the most recent problem for display
					$most_recent = null;
					$most_recent_clock = 0;
					
					foreach ($occurrence_data['problems'] as $problem) {
						if ($problem['clock'] > $most_recent_clock) {
							$most_recent_clock = $problem['clock'];
							$most_recent = $problem;
						}
					}

					if ($most_recent !== null) {
						$most_recent['recurrence_count'] = $accurate_count;
						$most_recent['first_occurrence'] = $occurrence_data['first_occurrence'];
						$most_recent['last_occurrence'] = $occurrence_data['last_occurrence'];
						$most_recent['resolved_count'] = $occurrence_data['resolved_count'];
						$most_recent['mttr_avg'] = $occurrence_data['mttr_avg'];
						$most_recent['mtbf_avg'] = $occurrence_data['mtbf_avg'];
						$most_recent['trend_delta'] = $accurate_count - ($prev_counts[$triggerid] ?? 0);
						$recurring_problems[] = $most_recent;
					}
				}
			}

			// Sort recurring problems
			[$sortfield, $sortorder] = self::getSorting($this->fields_values['sort_triggers']);
			$recurring_problems = self::sortRecurringProblems($recurring_problems, $sortfield, $sortorder);

			// Limit to show_lines
			if (count($recurring_problems) > $this->fields_values['show_lines']) {
				$info = _n('%1$d of %3$d%2$s recurring incident is shown', '%1$d of %3$d%2$s recurring incidents are shown',
					min($this->fields_values['show_lines'], count($recurring_problems)),
					(count($recurring_problems) > $search_limit) ? '+' : '',
					min($search_limit, count($recurring_problems))
				);
			}
			else {
				$info = '';
			}

			$recurring_problems = array_slice($recurring_problems, 0, $this->fields_values['show_lines'], true);

			$display_triggerids = array_values(array_unique(array_column($recurring_problems, 'objectid')));

			$events_full = $display_triggerids
				? API::Event()->get(array_merge($base_event_filter, [
					'output' => ['eventid', 'objectid', 'clock', 'r_eventid'],
					'objectids' => $display_triggerids,
					'time_from' => $time_from,
					'time_till' => $time_to
				]))
				: [];

			$trigger_stats = [];
			$r_eventids_full = [];

			foreach ($events_full as $event) {
				$tid = $event['objectid'];

				if (!array_key_exists($tid, $trigger_stats)) {
					$trigger_stats[$tid] = [
						'count' => 0,
						'first' => null,
						'last' => null,
						'resolved_sum' => 0,
						'resolved_count' => 0,
						'events' => []
					];
				}

				$clock = (int) $event['clock'];
				$trigger_stats[$tid]['count']++;
				$trigger_stats[$tid]['first'] = ($trigger_stats[$tid]['first'] === null) ? $clock : min($trigger_stats[$tid]['first'], $clock);
				$trigger_stats[$tid]['last'] = ($trigger_stats[$tid]['last'] === null) ? $clock : max($trigger_stats[$tid]['last'], $clock);
				$trigger_stats[$tid]['events'][(string) $event['eventid']] = [
					'clock' => $clock,
					'r_eventid' => (string) $event['r_eventid']
				];

				if ($event['r_eventid'] != 0) {
					$r_eventids_full[(string) $event['r_eventid']] = true;
				}
			}

			$r_events_full = $r_eventids_full
				? API::Event()->get([
					'output' => ['eventid', 'clock'],
					'eventids' => array_keys($r_eventids_full),
					'preservekeys' => true
				])
				: [];

			foreach ($trigger_stats as &$stats) {
				foreach ($stats['events'] as $e) {
					if ($e['r_eventid'] !== '0' && array_key_exists($e['r_eventid'], $r_events_full)) {
						$duration = (int) $r_events_full[$e['r_eventid']]['clock'] - (int) $e['clock'];
						if ($duration >= 0) {
							$stats['resolved_sum'] += $duration;
							$stats['resolved_count']++;
						}
					}
				}

				$stats['mttr_avg'] = $stats['resolved_count'] > 0
					? (int) round($stats['resolved_sum'] / $stats['resolved_count'])
					: null;

				$stats['mtbf_avg'] = ($stats['count'] >= 2 && $stats['first'] !== null && $stats['last'] !== null)
					? (int) round(($stats['last'] - $stats['first']) / ($stats['count'] - 1))
					: null;

				unset($stats['events'], $stats['resolved_sum']);
			}
			unset($stats);

			foreach ($recurring_problems as &$problem) {
				$tid = $problem['objectid'];

				if (array_key_exists($tid, $trigger_stats)) {
					$problem['resolved_count'] = $trigger_stats[$tid]['resolved_count'];
					$problem['mttr_avg'] = $trigger_stats[$tid]['mttr_avg'];
					$problem['mtbf_avg'] = $trigger_stats[$tid]['mtbf_avg'];
					$problem['first_occurrence'] = $trigger_stats[$tid]['first'] ?? $problem['first_occurrence'];
					$problem['last_occurrence'] = $trigger_stats[$tid]['last'] ?? $problem['last_occurrence'];
				}
			}
			unset($problem);

			$display_problems = [];
			foreach ($recurring_problems as $problem) {
				$display_problems[$problem['eventid']] = $problem;
			}

			$data['problems'] = $display_problems;

			$data = CScreenProblem::makeData($data, [
				'show' => $show,
				'details' => 0,
				'show_opdata' => OPERATIONAL_DATA_SHOW_NONE
			]);

			if ($data['problems']) {
				$data['triggers_hosts'] = getTriggersHostsList($data['triggers']);
			}

			if ($this->fields_values['show_tags']) {
				$data['tags'] = makeTags($data['problems'], true, 'eventid',
					$this->fields_values['show_tags'], $this->fields_values['tags'], null,
					$this->fields_values['tag_name_format'], $this->fields_values['tag_priority']
				);
			}

			if ($data['problems']) {
				$sli_cache = [];
				$service_cache = [];
				$service_tree_cache = [];
				$service_path_cache = [];

				foreach ($data['problems'] as $eventid => &$problem) {
					$problem_tags = [];

					if (array_key_exists('tags', $problem) && is_array($problem['tags'])) {
						foreach (array_slice($problem['tags'], 0, 50) as $tag) {
							if (!array_key_exists('tag', $tag)) {
								continue;
							}
							$problem_tags[] = [
								'tag' => $tag['tag'],
								'value' => $tag['value'] ?? ''
							];
						}
					}

					if (!$problem_tags) {
						continue;
					}

					$tags_key = md5(json_encode($problem_tags));

					if (!array_key_exists($tags_key, $service_cache)) {
						$services = API::Service()->get([
							'output' => ['serviceid', 'name'],
							'evaltype' => TAG_EVAL_TYPE_OR,
							'problem_tags' => $problem_tags,
							'sortfield' => 'name',
							'sortorder' => ZBX_SORT_UP,
							'limit' => 1
						]);

						$service_cache[$tags_key] = $services ? $services[0] : null;
					}

					$service = $service_cache[$tags_key];

					if ($service === null) {
						continue;
					}

					$serviceid = (int) $service['serviceid'];
					$problem['serviceid'] = $serviceid;
					$problem['service_name'] = $service['name'];

					if (!array_key_exists($serviceid, $service_tree_cache)) {
						$service_tree_cache[$serviceid] = self::buildServiceTree($serviceid, 300);
						$service_path_cache[$serviceid] = self::buildServiceHierarchyPath($serviceid, 30);
					}

					$problem['service_tree'] = $service_tree_cache[$serviceid];
					$problem['service_path'] = $service_path_cache[$serviceid];

					$sli_key = $serviceid.':'.$time_from.':'.$time_to;
					if (!array_key_exists($sli_key, $sli_cache)) {
						$slas = API::Sla()->get([
							'output' => ['slaid', 'name', 'slo', 'status'],
							'serviceids' => [$serviceid],
							'filter' => ['status' => ZBX_SLA_STATUS_ENABLED],
							'limit' => 1
						]);

						if (!$slas) {
							$sli_cache[$sli_key] = null;
						}
						else {
							$sla = $slas[0];

							$sli_response = API::Sla()->getSli([
								'slaid' => $sla['slaid'],
								'serviceids' => [$serviceid],
								'periods' => 1,
								'period_from' => $time_from,
								'period_to' => $time_to
							]);

							$sli_value = null;
							if (array_key_exists('serviceids', $sli_response) && array_key_exists('sli', $sli_response)) {
								$idx = array_search($serviceid, $sli_response['serviceids']);
								if ($idx !== false && isset($sli_response['sli'][0][$idx]['sli'])) {
									$sli_value = $sli_response['sli'][0][$idx]['sli'];
								}
							}

							$sli_cache[$sli_key] = [
								'sli' => $sli_value,
								'slaid' => (int) $sla['slaid'],
								'sla_name' => $sla['name'],
								'slo' => (float) $sla['slo']
							];
						}
					}

					if ($sli_cache[$sli_key] !== null) {
						$problem['sli'] = $sli_cache[$sli_key]['sli'];
						$problem['slaid'] = $sli_cache[$sli_key]['slaid'];
						$problem['sla_name'] = $sli_cache[$sli_key]['sla_name'];
						$problem['slo'] = $sli_cache[$sli_key]['slo'];
					}
				}
				unset($problem);
			}

			$this->setResponse(new CControllerResponseData($data + [
				'name' => $this->getInput('name', $this->widget->getDefaultName()),
				'error' => null,
				'initial_load' => (bool) $this->getInput('initial_load', 0),
				'fields' => [
					'show' => $show,
					'show_lines' => $this->fields_values['show_lines'],
					'show_tags' => $this->fields_values['show_tags'],
					'show_timeline' => $this->fields_values['show_timeline'],
					'tags' => $this->fields_values['tags'],
					'tag_name_format' => $this->fields_values['tag_name_format'],
					'tag_priority' => $this->fields_values['tag_priority'],
					'min_occurrences' => $min_occurrences,
					'time_period' => $this->fields_values['time_period']
				],
				'info' => $info,
				'sortfield' => $sortfield,
				'sortorder' => $sortorder,
				'user' => [
					'debug_mode' => $this->getDebugMode()
				],
				'config' => [
					'problem_ack_style' => CSettingsHelper::get(CSettingsHelper::PROBLEM_ACK_STYLE),
					'problem_unack_style' => CSettingsHelper::get(CSettingsHelper::PROBLEM_UNACK_STYLE),
					'blink_period' => CSettingsHelper::get(CSettingsHelper::BLINK_PERIOD)
				],
				'allowed' => [
					'ui_problems' => $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS),
					'add_comments' => $this->checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS),
					'change_severity' => $this->checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY),
					'acknowledge' => $this->checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS),
					'close' => $this->checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS),
					'suppress_problems' => $this->checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS),
					'rank_change' => $this->checkAccess(CRoleHelper::ACTIONS_CHANGE_PROBLEM_RANKING)
				]
			]));
		}
	}

	private static function getSorting(int $sort_triggers): array {
		switch ($sort_triggers) {
			case SCREEN_SORT_TRIGGERS_TIME_ASC:
				return ['clock', ZBX_SORT_UP];

			case SCREEN_SORT_TRIGGERS_TIME_DESC:
			default:
				return ['clock', ZBX_SORT_DOWN];

			case SCREEN_SORT_TRIGGERS_SEVERITY_ASC:
				return ['severity', ZBX_SORT_UP];

			case SCREEN_SORT_TRIGGERS_SEVERITY_DESC:
				return ['severity', ZBX_SORT_DOWN];

			case SCREEN_SORT_TRIGGERS_HOST_NAME_ASC:
				return ['host', ZBX_SORT_UP];

			case SCREEN_SORT_TRIGGERS_HOST_NAME_DESC:
				return ['host', ZBX_SORT_DOWN];

			case SCREEN_SORT_TRIGGERS_NAME_ASC:
				return ['name', ZBX_SORT_UP];

			case SCREEN_SORT_TRIGGERS_NAME_DESC:
				return ['name', ZBX_SORT_DOWN];

			case 999:
				return ['recurrence_count', ZBX_SORT_DOWN];

			case 998:
				return ['recurrence_count', ZBX_SORT_UP];
		}
	}

	private static function sortRecurringProblems(array $problems, string $sortfield, string $sortorder): array {
		usort($problems, function($a, $b) use ($sortfield, $sortorder) {
			$result = 0;

			switch ($sortfield) {
				case 'clock':
					$result = $a['clock'] <=> $b['clock'];
					break;
				case 'severity':
					$result = $b['severity'] <=> $a['severity']; // Higher severity first
					break;
				case 'name':
					$result = strcmp($a['name'], $b['name']);
					break;
				case 'host':
					$result = 0;
					break;
				case 'recurrence_count':
					$result = ($a['recurrence_count'] ?? 0) <=> ($b['recurrence_count'] ?? 0);
					break;
			}

			return ($sortorder === ZBX_SORT_UP) ? $result : -$result;
		});

		return $problems;
	}

	private static function buildServiceHierarchyPath(int $serviceid, int $max_depth): array {
		$path = [];
		$current_serviceid = $serviceid;
		$visited = [];

		for ($i = 0; $i < $max_depth && $current_serviceid != 0; $i++) {
			if (array_key_exists($current_serviceid, $visited)) {
				break;
			}

			$visited[$current_serviceid] = true;

			$services = API::Service()->get([
				'output' => ['serviceid', 'name', 'status'],
				'serviceids' => [$current_serviceid],
				'selectParents' => ['serviceid'],
				'preservekeys' => true,
				'limit' => 1
			]);

			if (!$services) {
				break;
			}

			$service = $services[array_key_first($services)];

			array_unshift($path, [
				'serviceid' => (int) $service['serviceid'],
				'name' => $service['name'],
				'status' => (int) $service['status']
			]);

			$current_serviceid = (!empty($service['parents'])) ? (int) $service['parents'][0]['serviceid'] : 0;
		}

		return $path;
	}

	private static function buildServiceTree(int $serviceid, int $limit): array {
		$root_services = API::Service()->get([
			'output' => ['serviceid', 'name', 'status'],
			'serviceids' => [$serviceid],
			'selectParents' => ['serviceid'],
			'preservekeys' => true,
			'limit' => 1
		]);

		if (!$root_services) {
			return [
				'root' => null,
				'count' => 0,
				'truncated' => false
			];
		}

		$root = $root_services[array_key_first($root_services)];

		$descendants = API::Service()->get([
			'output' => ['serviceid', 'name', 'status'],
			'parentids' => [$serviceid],
			'deep_parentids' => true,
			'selectParents' => ['serviceid'],
			'preservekeys' => true,
			'limit' => $limit + 1
		]);

		$truncated = (count($descendants) > $limit);
		if ($truncated) {
			$descendants = array_slice($descendants, 0, $limit, true);
		}

		$nodes = [$serviceid => $root] + $descendants;

		$children = [];
		foreach ($nodes as $nodeid => $node) {
			$children[(int) $nodeid] = [];
		}

		foreach ($nodes as $nodeid => $node) {
			$nodeid = (int) $nodeid;

			if ($nodeid === $serviceid) {
				continue;
			}

			$parentid = $serviceid;

			if (!empty($node['parents'])) {
				foreach ($node['parents'] as $p) {
					$pid = (int) $p['serviceid'];
					if (array_key_exists($pid, $nodes)) {
						$parentid = $pid;
						break;
					}
				}
			}

			$children[$parentid][] = $nodeid;
		}

		$build = function(int $id) use (&$build, $nodes, $children): array {
			$node = $nodes[$id];
			$item = [
				'serviceid' => (int) $node['serviceid'],
				'name' => $node['name'],
				'status' => (int) $node['status'],
				'children' => []
			];

			foreach ($children[$id] as $cid) {
				$item['children'][] = $build($cid);
			}

			return $item;
		};

		return [
			'root' => $build($serviceid),
			'count' => count($nodes),
			'truncated' => $truncated
		];
	}
}
