/* Xolution MailDelivery settings page: email-type accordion, confirm dialogs for the check buttons. */
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
