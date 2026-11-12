/* Xolution MailDelivery settings page: email-type accordion, the setup wizard, confirm dialogs for the check buttons. */
(() => {
	'use strict';

	const DURATION = 300; // keep in step with --xmd-duration in admin.css
	const SCROLL_GAP = 10; // px between the opened trigger and the top of the viewport (below the admin bar)
	const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2); // matches cubic-bezier(.65,0,.35,1) closely

	// ------------------------------------------------------------ accordion

	/** Height of the fixed admin bar, which covers the top of the page. */
	const topInset = () => {
		const bar = document.getElementById('wpadminbar');
		return bar && getComputedStyle(bar).position === 'fixed' ? bar.offsetHeight : 0;
	};

	const animateScroll = (to) => {
		to = Math.max(0, Math.round(to));
		if (reducedMotion) {
			window.scrollTo(0, to);
			return;
		}
		const from = window.scrollY;
		const distance = to - from;
		const start = performance.now();
		const step = (now) => {
			const progress = Math.min(1, (now - start) / DURATION);
			window.scrollTo(0, from + distance * ease(progress));
			if (progress < 1) {
				requestAnimationFrame(step);
			}
		};
		requestAnimationFrame(step);
	};

	/** Runs done() once the panel's height transition ends; a newer animation on the same panel cancels it. */
	const afterTransition = (panel, done) => {
		const token = {};
		panel._xmdToken = token;
		const finish = () => {
			if (panel._xmdToken === token) {
				done();
			}
		};
		panel.addEventListener('transitionend', (e) => e.propertyName === 'height' && finish(), { once: true });
		setTimeout(finish, DURATION + 50); // in case transitionend never fires
	};

	const setOpen = (item, open) => {
		const trigger = item.querySelector('.xmd-acc__trigger');
		const panel = item.querySelector('.xmd-acc__panel');
		trigger.setAttribute('aria-expanded', String(open));
		item.classList.toggle('is-open', open);

		if (reducedMotion) {
			panel.hidden = !open;
			return;
		}
		if (open) {
			panel.hidden = false;
			const target = panel.scrollHeight;
			panel.style.height = '0px';
			void panel.offsetHeight; // commit the start height before transitioning
			panel.style.height = `${target}px`;
			afterTransition(panel, () => {
				panel.style.height = '';
			});
		} else {
			panel.style.height = `${panel.getBoundingClientRect().height}px`;
			void panel.offsetHeight;
			panel.style.height = '0px';
			afterTransition(panel, () => {
				panel.hidden = true;
				panel.style.height = '';
			});
		}
	};

	document.querySelectorAll('[data-xmd-accordion]').forEach((accordion) => {
		const items = [...accordion.querySelectorAll('.xmd-acc__item')];

		items.forEach((item) => {
			item.querySelector('.xmd-acc__trigger').addEventListener('click', () => {
				if (item.classList.contains('is-open')) {
					setOpen(item, false);
					return;
				}

				// Only one open: close the current one. If it sits above this one, this trigger moves up by the
				// height it loses, so aim the scroll at where the trigger will end up.
				const current = items.find((other) => other.classList.contains('is-open'));
				let shift = 0;
				if (current) {
					if (current.compareDocumentPosition(item) & Node.DOCUMENT_POSITION_FOLLOWING) {
						shift = current.querySelector('.xmd-acc__panel').getBoundingClientRect().height;
					}
					setOpen(current, false);
				}
				const trigger = item.querySelector('.xmd-acc__trigger');
				const target = trigger.getBoundingClientRect().top + window.scrollY - shift - topInset() - SCROLL_GAP;
				setOpen(item, true);
				animateScroll(target);
			});
		});
	});

	// ------------------------------------------------------------ select all + live status

	document.querySelectorAll('[data-xmd-accordion] .xmd-acc__item').forEach((item) => {
		const panel = item.querySelector('.xmd-acc__panel');
		const status = item.querySelector('.xmd-acc__status[data-format]');
		const boxes = [...panel.querySelectorAll('input[type="checkbox"]')];

		// Each "Select all" works on its own group, or on the whole section when it has no groups.
		const buttons = [...item.querySelectorAll('.xmd-select-all')].map((button) => ({
			button,
			selectable: [...button.closest('[data-xmd-select-scope]').querySelectorAll('input[type="checkbox"]')].filter(
				(box) => !box.disabled
			),
		}));
		const allChecked = (selectable) => selectable.every((box) => box.checked);

		const refresh = () => {
			if (status) {
				const on = boxes.filter((box) => box.checked).length;
				status.textContent = status.dataset.format.replace('{on}', on).replace('{total}', boxes.length);
				status.classList.toggle('has-on', on > 0);
			}
			buttons.forEach(({ button, selectable }) => {
				button.textContent = allChecked(selectable) ? button.dataset.deselect : button.dataset.select;
			});
		};

		buttons.forEach(({ button, selectable }) => {
			const tools = button.closest('.xmd-acc__tools');
			if (tools) {
				tools.hidden = false;
			}
			button.hidden = false;
			button.addEventListener('click', () => {
				const check = !allChecked(selectable);
				selectable.forEach((box) => {
					box.checked = check;
				});
				refresh();
			});
		});
		panel.addEventListener('change', refresh);
		refresh();
	});

	// ------------------------------------------------------------ setup: checklist

	document.querySelectorAll('[data-xmd-check]').forEach((item) => {
		const all = item.querySelector('.xmd-check__all');
		const count = item.querySelector('.xmd-check__count');
		const more = item.querySelector('.xmd-check__more');
		const list = item.querySelector('.xmd-check__types');
		const boxes = [...list.querySelectorAll('input[type="checkbox"]')].filter((box) => !box.disabled);

		const refresh = () => {
			const on = boxes.filter((box) => box.checked).length;
			all.checked = boxes.length > 0 && on === boxes.length;
			all.indeterminate = on > 0 && on < boxes.length;
			item.classList.toggle('has-on', on > 0);
			if (count) {
				count.textContent = boxes.length ? count.dataset.format.replace('{on}', on).replace('{total}', boxes.length) : '';
			}
		};
		item._xmdRefresh = refresh;

		// Folded away until asked for: ticking the integration is the normal way.
		if (more) {
			list.hidden = true;
			more.hidden = false;
			more.addEventListener('click', () => {
				const open = list.hidden;
				list.hidden = !open;
				more.setAttribute('aria-expanded', String(open));
				more.textContent = open ? more.dataset.close : more.dataset.open;
			});
		} else {
			list.hidden = true;
		}

		all.addEventListener('change', () => {
			boxes.forEach((box) => {
				box.checked = all.checked;
			});
			refresh();
		});
		list.addEventListener('change', refresh);
		refresh();
	});

	// ------------------------------------------------------------ setup: config file

	const setup = document.querySelector('[data-xmd-setup]');
	if (setup) {
		const drop = setup.querySelector('.xmd-drop');
		const input = setup.querySelector('.xmd-drop__input');
		const status = setup.querySelector('.xmd-drop__status');
		const skippedBox = setup.querySelector('[data-xmd-skipped]');
		const field = (name) => setup.querySelector(`[data-xmd-field="${name}"]`);

		const say = (lines, isError) => {
			status.replaceChildren(
				...lines.map((line) => {
					const p = document.createElement('p');
					p.textContent = line;
					return p;
				})
			);
			status.classList.toggle('is-error', Boolean(isError));
		};

		/** Fills the form from a config the server read and checked, and marks what changed. */
		const apply = (config) => {
			const set = (name, value) => {
				const el = field(name);
				if (!el) {
					return;
				}
				if (el.type === 'checkbox') {
					el.checked = Boolean(value);
				} else if (value !== '' || name !== 'slack_webhook') {
					el.value = value; // an empty webhook keeps the saved one
				}
				el.classList.remove('xmd-filled');
				void el.offsetWidth;
				el.classList.add('xmd-filled');
			};
			set('account_id', config.account_id);
			set('sending_domain', config.sending_domain);
			set('server_ips', config.server_ips.join('\n'));
			set('slack_webhook', config.slack_webhook);
			set('sandbox', config.sandbox);

			const wanted = new Set(config.enabled_types);
			const present = new Set();
			setup.querySelectorAll('input[name="enabled_types[]"]').forEach((box) => {
				present.add(box.value);
				if (!box.disabled) {
					box.checked = wanted.has(box.value);
				}
			});
			// Emails this site can't switch on go along, only to be reported as skipped.
			skippedBox.replaceChildren(
				...config.enabled_types
					.filter((marker) => !present.has(marker) || setup.querySelector(`input[name="enabled_types[]"][value="${CSS.escape(marker)}"]:disabled`))
					.map((marker) => Object.assign(document.createElement('input'), { type: 'hidden', name: 'skipped_types[]', value: marker }))
			);
			setup.querySelectorAll('[data-xmd-check]').forEach((item) => item._xmdRefresh && item._xmdRefresh());
		};

		const read = (file) => {
			if (!file) {
				return;
			}
			file.text()
				.then((text) => {
					const body = new FormData();
					body.append('action', 'xmd_read_config');
					body.append('_wpnonce', setup.dataset.readNonce);
					body.append('config', text);
					return fetch(setup.dataset.readUrl, { method: 'POST', body, credentials: 'same-origin' });
				})
				.then((response) => response.json())
				.then((json) => {
					if (!json || !json.success) {
						say([(json && json.data && json.data.message) || setup.dataset.readFailed], true);
						return;
					}
					apply(json.data.config);
					say([setup.dataset.readOk.replace('%s', file.name), ...json.data.notes], false);
					drop.classList.add('is-loaded');
				})
				.catch(() => say([setup.dataset.readFailed], true));
		};

		drop.hidden = false;
		input.addEventListener('change', () => read(input.files[0]));
		['dragenter', 'dragover'].forEach((type) =>
			drop.addEventListener(type, (event) => {
				event.preventDefault();
				drop.classList.add('is-over');
			})
		);
		['dragleave', 'dragend', 'drop'].forEach((type) => drop.addEventListener(type, () => drop.classList.remove('is-over')));
		drop.addEventListener('drop', (event) => {
			event.preventDefault();
			read(event.dataTransfer.files[0]);
		});

		// No second run while the first is still talking to AhaSend.
		setup.addEventListener('submit', () => {
			setup.querySelector('.xmd-setup__submit').disabled = true;
			setup.classList.add('is-busy');
		});
	}

	// ------------------------------------------------------------ setup: report

	const report = document.querySelector('[data-xmd-report]');
	if (report && typeof report.showModal === 'function') {
		report.close();
		report.showModal();
		report.addEventListener('click', (event) => {
			if (event.target === report) {
				report.close();
			}
		});
	}

	// ------------------------------------------------------------ confirm dialog

	const dialog = document.getElementById('xmd-confirm');
	if (dialog && typeof dialog.showModal === 'function') {
		const title = dialog.querySelector('.xmd-dialog__title');
		const body = dialog.querySelector('.xmd-dialog__body');
		const ok = dialog.querySelector('[value="ok"]');
		let pending = null;

		document.querySelectorAll('form[data-xmd-confirm-title]').forEach((form) => {
			form.addEventListener('submit', (event) => {
				if (form.dataset.xmdConfirmed === '1') {
					return;
				}
				event.preventDefault();
				pending = form;
				title.textContent = form.dataset.xmdConfirmTitle;
				body.replaceChildren(
					...form.dataset.xmdConfirmBody.split('\n').filter(Boolean).map((line) => {
						const p = document.createElement('p');
						p.textContent = line;
						return p;
					})
				);
				ok.textContent = form.dataset.xmdConfirmOk;
				dialog.returnValue = '';
				dialog.showModal();
				dialog.querySelector('[value="cancel"]').focus();
			});
		});

		dialog.addEventListener('close', () => {
			if (pending && dialog.returnValue === 'ok') {
				pending.dataset.xmdConfirmed = '1';
				pending.querySelector('[type="submit"]').disabled = true; // no double submit while the page loads
				pending.submit();
			}
			pending = null;
		});

		// A click on the backdrop cancels, like Escape.
		dialog.addEventListener('click', (event) => {
			if (event.target === dialog) {
				dialog.close('cancel');
			}
		});
	}
})();
