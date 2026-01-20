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


/**
 * Recurring Incidents widget form view.
 *
 * @var CView $this
 * @var array $data
 */

$form = (new CWidgetFormView($data));

$groupids = array_key_exists('groupids', $data['fields'])
	? new CWidgetFieldMultiSelectGroupView($data['fields']['groupids'])
	: null;

$form
	->addField(array_key_exists('show', $data['fields']) && $data['fields']['show'] !== null
		? new CWidgetFieldRadioButtonListView($data['fields']['show'])
		: null
	)
	->addField($groupids)
	->addField(array_key_exists('exclude_groupids', $data['fields'])
		? new CWidgetFieldMultiSelectGroupView($data['fields']['exclude_groupids'])
		: null
	)
	->addField(array_key_exists('hostids', $data['fields'])
		? (new CWidgetFieldMultiSelectHostView($data['fields']['hostids']))
			->setFilterPreselect([
				'id' => $groupids->getId(),
				'accept' => CMultiSelect::FILTER_PRESELECT_ACCEPT_ID,
				'submit_as' => 'groupid'
			])
		: null
	)
	->addField(array_key_exists('problem', $data['fields']) && $data['fields']['problem'] !== null
		? new CWidgetFieldTextBoxView($data['fields']['problem'])
		: null
	)
	->addField(array_key_exists('severities', $data['fields']) && $data['fields']['severities'] !== null
		? new CWidgetFieldSeveritiesView($data['fields']['severities'])
		: null
	)
	->addField(array_key_exists('evaltype', $data['fields']) && $data['fields']['evaltype'] !== null
		? new CWidgetFieldRadioButtonListView($data['fields']['evaltype'])
		: null
	)
	->addField(array_key_exists('tags', $data['fields']) && $data['fields']['tags'] !== null
		? new CWidgetFieldTagsView($data['fields']['tags'])
		: null
	)
	->addField(array_key_exists('show_tags', $data['fields']) && $data['fields']['show_tags'] !== null
		? new CWidgetFieldRadioButtonListView($data['fields']['show_tags'])
		: null
	)
	->addField(array_key_exists('tag_name_format', $data['fields']) && $data['fields']['tag_name_format'] !== null
		? new CWidgetFieldRadioButtonListView($data['fields']['tag_name_format'])
		: null
	)
	->addField(array_key_exists('tag_priority', $data['fields']) && $data['fields']['tag_priority'] !== null
		? (new CWidgetFieldTextBoxView($data['fields']['tag_priority']))->setPlaceholder(_('comma-separated list'))
		: null
	)
	->addField(array_key_exists('min_occurrences', $data['fields']) && $data['fields']['min_occurrences'] !== null
		? (new CWidgetFieldIntegerBoxView($data['fields']['min_occurrences']))
			->setFieldHint(makeHelpIcon(
				_('Minimum number of times a problem must occur to be considered recurring')
			))
		: null
	)
	->addField(array_key_exists('time_period', $data['fields']) && $data['fields']['time_period'] !== null
		? (new CWidgetFieldTimePeriodView($data['fields']['time_period']))
			->setDateFormat(ZBX_FULL_DATE_TIME)
			->setFromPlaceholder(_('YYYY-MM-DD hh:mm:ss'))
			->setToPlaceholder(_('YYYY-MM-DD hh:mm:ss'))
		: null
	)
	->addField(array_key_exists('sort_triggers', $data['fields']) && $data['fields']['sort_triggers'] !== null
		? new CWidgetFieldSelectView($data['fields']['sort_triggers'])
		: null
	)
	->addField(array_key_exists('show_timeline', $data['fields']) && $data['fields']['show_timeline'] !== null
		? new CWidgetFieldCheckBoxView($data['fields']['show_timeline'])
		: null
	)
	->addField(array_key_exists('show_lines', $data['fields']) && $data['fields']['show_lines'] !== null
		? new CWidgetFieldIntegerBoxView($data['fields']['show_lines'])
		: null
	)
	->includeJsFile('widget.edit.js.php')
	->addJavaScript('widget_recurring_incidents_form.init('.json_encode([
		'sort_with_enabled_show_timeline' => [
			SCREEN_SORT_TRIGGERS_TIME_DESC => true,
			SCREEN_SORT_TRIGGERS_TIME_ASC => true
		]
	], JSON_THROW_ON_ERROR).');')
	->show();