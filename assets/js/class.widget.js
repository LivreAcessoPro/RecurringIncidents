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


class CWidgetRecurringIncidents extends CWidget {

	/**
	 * Table body of recurring incidents.
	 *
	 * @type {HTMLElement|null}
	 */
	#table_body = null;

	/**
	 * ID of selected event.
	 *
	 * @type {string|null}
	 */
	#selected_eventid = null;

	onInitialize() {
		this._opened_eventids = [];
	}

	onStart() {
		this._events = {
			...this._events,

			acknowledgeCreated: (e, response) => {
				clearMessages();
				addMessage(makeMessageBox('good', [], response.success.title));

				if (this._state === WIDGET_STATE_ACTIVE) {
					this._startUpdating();
				}
			},

			rankChanged: () => {
				if (this._state === WIDGET_STATE_ACTIVE) {
					this._startUpdating();
				}
			},
		}
	}

	onActivate() {
		$.subscribe('acknowledge.create', this._events.acknowledgeCreated);
		$.subscribe('event.rank_change', this._events.rankChanged);
	}

	onDeactivate() {
		$.unsubscribe('acknowledge.create', this._events.acknowledgeCreated);
		$.unsubscribe('event.rank_change', this._events.rankChanged);
	}

	setContents(response) {
		super.setContents(response);

		this.#table_body = this._contents.querySelector(`.${ZBX_STYLE_LIST_TABLE} tbody`);

		if (this.#table_body === null) {
			return;
		}

		this.#activateContentsEvents();

		if (!this.hasEverUpdated() && this.isReferred()) {
			this.#selected_eventid = this.#getDefaultSelectable();

			if (this.#selected_eventid !== null) {
				this.#selectEvent();
				this.#broadcast();
			}
		}
		else if (this.#selected_eventid !== null) {
			this.#selectEvent();
		}
	}

	onReferredUpdate() {
		if (this.#table_body === null || this.#selected_eventid !== null) {
			return;
		}

		this.#selected_eventid = this.#getDefaultSelectable();

		if (this.#selected_eventid !== null) {
			this.#selectEvent();
			this.#broadcast();
		}
	}

	#getDefaultSelectable() {
		const row = this.#table_body.querySelector('[data-eventid]');

		return row !== null ? row.dataset.eventid : null;
	}

	#activateContentsEvents() {
		this.#table_body?.addEventListener('click', e => this.#onTableBodyClick(e));
	}

	#selectEvent() {
		const rows = this.#table_body.querySelectorAll('[data-eventid]');

		for (const row of rows) {
			row.classList.toggle(ZBX_STYLE_ROW_SELECTED, row.dataset.eventid === this.#selected_eventid);
		}
	}

	#broadcast() {
		this.broadcast({[CWidgetsData.DATA_TYPE_EVENT_ID]: [this.#selected_eventid]});
	}

	#onTableBodyClick(e) {
		if (e.target.closest('a') !== null || e.target.closest('[data-hintbox="1"]') !== null) {
			return;
		}

		const row = e.target.closest('[data-eventid]');

		if (row !== null) {
			this.#selected_eventid = row.dataset.eventid;

			this.#selectEvent();
			this.#broadcast();
		}
	}
}