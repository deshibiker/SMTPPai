(function () {
	'use strict';

	var cfg = window.mailpaiSmtpAdmin || {};

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (key) {
			body.append(key, data[key]);
		});
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
			return r.json();
		});
	}

	function showAdminNotice(message, type, actionLink) {
		var host = document.querySelector('.mailpai-smtp-app__body');
		if (!host || !message) {
			return;
		}
		var existing = host.querySelector('.mailpai-smtp-flash-notice');
		if (existing) {
			existing.remove();
		}
		var notice = document.createElement('div');
		var isSuccess = type === 'success';
		notice.className = 'mailpai-smtp-flash-notice mailpai-smtp-flash-notice--' + (isSuccess ? 'success' : 'error');
		notice.setAttribute('role', isSuccess ? 'status' : 'alert');
		if (isSuccess) {
			var title = document.createElement('strong');
			title.className = 'mailpai-smtp-flash-notice__title';
			title.textContent = (cfg.i18n && cfg.i18n.testSuccess) || 'Success';
			var text = document.createElement('span');
			text.className = 'mailpai-smtp-flash-notice__text';
			text.textContent = message;
			notice.appendChild(title);
			notice.appendChild(text);
		} else {
			var errorTitle = document.createElement('strong');
			errorTitle.className = 'mailpai-smtp-flash-notice__title';
			errorTitle.textContent = (cfg.i18n && cfg.i18n.testFailed) || 'Test failed.';
			var errorText = document.createElement('span');
			errorText.className = 'mailpai-smtp-flash-notice__text';
			errorText.textContent = message;
			notice.appendChild(errorTitle);
			notice.appendChild(errorText);
		}
		if (actionLink && actionLink.url) {
			var action = document.createElement('a');
			action.className = 'mailpai-smtp-btn mailpai-smtp-btn--sm mailpai-smtp-flash-notice__action';
			action.href = actionLink.url;
			action.textContent = actionLink.label || ((cfg.i18n && cfg.i18n.connectAccount) || 'Connect account');
			notice.appendChild(action);
		}
		host.insertBefore(notice, host.firstChild);
		window.setTimeout(function () {
			if (notice.parentNode) {
				notice.remove();
			}
		}, 8000);
		if (typeof notice.scrollIntoView === 'function') {
			notice.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		}
	}

	function updateConnectionCard(card, status, errorMessage) {
		if (!card) {
			return;
		}
		var labels = (cfg.i18n && cfg.i18n.connectionStatus) || {};
		card.className = card.className.replace(/mailpai-smtp-conn-card--\w+/g, '').trim();
		card.classList.add('mailpai-smtp-conn-card', 'mailpai-smtp-conn-card--' + status);
		var badge = card.querySelector('.mailpai-smtp-badge');
		if (badge) {
			badge.className = 'mailpai-smtp-badge mailpai-smtp-badge--' + status;
			badge.textContent = labels[status] || status;
		}
		var alertBox = card.querySelector('.mailpai-smtp-conn-card__alert');
		if ('failed' === status && errorMessage) {
			if (!alertBox) {
				alertBox = document.createElement('div');
				alertBox.className = 'mailpai-smtp-conn-card__alert';
				alertBox.setAttribute('role', 'alert');
				var errorEl = document.createElement('p');
				errorEl.className = 'mailpai-smtp-conn-card__error';
				alertBox.appendChild(errorEl);
				card.appendChild(alertBox);
			}
			var errorText = alertBox.querySelector('.mailpai-smtp-conn-card__error');
			if (errorText) {
				errorText.textContent = errorMessage;
			}
		} else if (alertBox) {
			alertBox.remove();
		}
	}

	function getLogModal() {
		return document.getElementById('mailpai-smtp-log-modal');
	}

	var testModalState = {
		card: null,
	};

	function getTestModal() {
		return document.getElementById('mailpai-smtp-test-modal');
	}

	function getTestEmailInput() {
		return document.getElementById('mailpai-smtp-test-email');
	}

	function getTestModalTitleEl() {
		return document.getElementById('mailpai-smtp-test-modal-title');
	}

	function replaceI18nPlaceholder(template, value) {
		return String(template || '').replace('%s', value);
	}

	function replaceI18nPlaceholders(template, first, second) {
		return String(template || '')
			.replace('%1$s', first)
			.replace('%2$s', second);
	}

	function setTestModalTitle(connectionTitle) {
		var titleEl = getTestModalTitleEl();
		if (!titleEl) {
			return;
		}
		var title = String(connectionTitle || '').trim();
		if ('' === title) {
			titleEl.textContent = (cfg.i18n && cfg.i18n.testModalTitleDefault) || 'Send test email';
			return;
		}
		titleEl.textContent = replaceI18nPlaceholder(
			(cfg.i18n && cfg.i18n.testModalTitle) || 'Send test email — %s',
			title
		);
	}

	function getTestModalConnectionTitle(modal) {
		if (!modal) {
			return '';
		}
		return String(modal.dataset.connectionTitle || '').trim();
	}

	function fallbackTestSuccessMessage(connectionTitle, to) {
		var title = String(connectionTitle || '').trim();
		var recipient = String(to || '').trim();
		if ('' !== title && '' !== recipient) {
			return replaceI18nPlaceholders(
				(cfg.i18n && cfg.i18n.testSentForTo) || 'Test email sent for %1$s to %2$s.',
				title,
				recipient
			);
		}
		if ('' !== title) {
			return replaceI18nPlaceholder(
				(cfg.i18n && cfg.i18n.testSentFor) || 'Test email sent for %s.',
				title
			);
		}
		return (cfg.i18n && cfg.i18n.testSent) || 'Test email sent.';
	}

	function fallbackTestFailureMessage(connectionTitle) {
		var title = String(connectionTitle || '').trim();
		if ('' !== title) {
			return replaceI18nPlaceholder(
				(cfg.i18n && cfg.i18n.testFailedFor) || 'Test failed for %s.',
				title
			);
		}
		return (cfg.i18n && cfg.i18n.testFailed) || 'Test failed.';
	}

	function showTestModalError(message) {
		var errorEl = document.getElementById('mailpai-smtp-test-modal-error');
		if (!errorEl) {
			return;
		}
		if (!message) {
			errorEl.hidden = true;
			errorEl.textContent = '';
			return;
		}
		errorEl.hidden = false;
		errorEl.textContent = message;
	}

	function closeTestModal() {
		var modal = getTestModal();
		if (modal) {
			modal.hidden = true;
			delete modal.dataset.connectionId;
			delete modal.dataset.connectionTitle;
		}
		testModalState.card = null;
		document.body.classList.remove('mailpai-smtp-modal-open');
		showTestModalError('');
		setTestModalTitle('');
	}

	function openTestModal(connectionId, card, connectionTitle) {
		var modal = getTestModal();
		if (!modal || !connectionId) {
			return;
		}
		var title = String(connectionTitle || '').trim();
		if ('' === title && card) {
			var heading = card.querySelector('.mailpai-smtp-conn-card__head h3');
			title = heading ? String(heading.textContent || '').trim() : '';
		}
		modal.dataset.connectionId = connectionId;
		modal.dataset.connectionTitle = title;
		testModalState.card = card || null;
		showTestModalError('');
		setTestModalTitle(title);
		var input = getTestEmailInput();
		if (input) {
			input.value = cfg.adminEmail || '';
		}
		modal.hidden = false;
		document.body.classList.add('mailpai-smtp-modal-open');
		if (input) {
			window.setTimeout(function () {
				input.focus();
				input.select();
			}, 0);
		}
	}

	function isValidEmail(value) {
		var email = String(value || '').trim();
		if (!email) {
			return false;
		}
		var input = getTestEmailInput();
		if (input && typeof input.checkValidity === 'function') {
			input.value = email;
			return input.checkValidity();
		}
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
	}

	function runConnectionTest(connectionId, to, card) {
		var modal = getTestModal();
		var submitBtn = modal ? modal.querySelector('.mailpai-smtp-test-modal__submit') : null;
		var defaultLabel = submitBtn ? (submitBtn.getAttribute('data-default-label') || submitBtn.textContent) : '';
		if (submitBtn && !submitBtn.getAttribute('data-default-label')) {
			submitBtn.setAttribute('data-default-label', defaultLabel);
		}
		if (submitBtn) {
			submitBtn.disabled = true;
			submitBtn.textContent = (cfg.i18n && cfg.i18n.testing) || 'Testing…';
		}
		return post('mailpai_smtp_test_connection', { connection_id: connectionId, to: to })
			.then(function (res) {
				var message = (res.data && res.data.message) || '';
				var connectionTitle = getTestModalConnectionTitle(modal);
				if (res.success) {
					closeTestModal();
					showAdminNotice(message || fallbackTestSuccessMessage(connectionTitle, to), 'success');
					updateConnectionCard(card, 'working', '');
					return;
				}
				closeTestModal();
				var actionLink = null;
				if (res.data && res.data.oauth_signin_url) {
					actionLink = {
						url: res.data.oauth_signin_url,
						label: res.data.oauth_signin_label || ''
					};
				}
				showAdminNotice(message || fallbackTestFailureMessage(connectionTitle), 'error', actionLink);
				if (!actionLink) {
					updateConnectionCard(card, 'failed', message);
				}
			})
			.catch(function () {
				var connectionTitle = getTestModalConnectionTitle(modal);
				closeTestModal();
				showAdminNotice(fallbackTestFailureMessage(connectionTitle), 'error');
			})
			.finally(function () {
				if (submitBtn) {
					submitBtn.disabled = false;
					submitBtn.textContent = defaultLabel;
				}
			});
	}

	function closeLogModal() {
		var modal = getLogModal();
		if (modal) {
			modal.hidden = true;
			document.body.classList.remove('mailpai-smtp-log-modal-open');
		}
	}

	function openLogModal() {
		var modal = getLogModal();
		if (modal) {
			modal.hidden = false;
			document.body.classList.add('mailpai-smtp-log-modal-open');
		}
	}

	function getLogFilters() {
		var params = new URLSearchParams(window.location.search);
		return {
			log_status: params.get('log_status') || '',
			log_s: params.get('log_s') || '',
			log_from: params.get('log_from') || '',
			log_to: params.get('log_to') || ''
		};
	}

	function updateLogModalNav(prevId, nextId) {
		var modal = getLogModal();
		if (!modal) {
			return;
		}
		var prevBtn = modal.querySelector('.mailpai-smtp-log-modal__nav--prev');
		var nextBtn = modal.querySelector('.mailpai-smtp-log-modal__nav--next');
		if (prevBtn) {
			prevBtn.disabled = !prevId;
			prevBtn.dataset.logId = prevId ? String(prevId) : '';
		}
		if (nextBtn) {
			nextBtn.disabled = !nextId;
			nextBtn.dataset.logId = nextId ? String(nextId) : '';
		}
	}

	function loadLogDetail(logId, openModal) {
		var payload = Object.assign({ log_id: logId }, getLogFilters());
		return post('mailpai_smtp_log_view', payload).then(function (res) {
			if (!res.success || !res.data || !res.data.log) {
				return;
			}
			var body = document.getElementById('mailpai-smtp-log-modal-body');
			if (!body) {
				return;
			}
			body.innerHTML = renderLogDetail(res.data.log);
			updateLogModalNav(res.data.prev_id || 0, res.data.next_id || 0);
			if (openModal) {
				openLogModal();
			}
			body.scrollTop = 0;
			window.requestAnimationFrame(function () {
				resizeLogBodyFrames(body);
				window.setTimeout(function () {
					resizeLogBodyFrames(body);
				}, 150);
			});
		});
	}

	function getProviderPanel() {
		return document.getElementById('mailpai-smtp-provider-panel');
	}

	function setProviderPanelOpen(open) {
		var panel = getProviderPanel();
		if (!panel) {
			return;
		}
		panel.hidden = !open;
		document.querySelectorAll('.mailpai-smtp-add-btn').forEach(function (btn) {
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
		var empty = document.getElementById('mailpai-smtp-connect-empty');
		if (empty) {
			empty.hidden = open;
		}
		var cta = document.getElementById('mailpai-smtp-connect-cta');
		if (cta) {
			cta.classList.toggle('is-open', open);
		}
	}

	function openProviderPanel() {
		setProviderPanelOpen(true);
		var panel = getProviderPanel();
		if (panel && typeof panel.scrollIntoView === 'function') {
			panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}

	function closeProviderPanel() {
		setProviderPanelOpen(false);
	}

	document.addEventListener('click', function (e) {
		var addBtn = e.target.closest('.mailpai-smtp-add-btn');
		if (addBtn) {
			e.preventDefault();
			openProviderPanel();
			return;
		}

		if (e.target.closest('.mailpai-smtp-provider-cancel')) {
			e.preventDefault();
			closeProviderPanel();
			return;
		}

		var hintTrigger = e.target.closest('.mailpai-smtp-hints-accordion__trigger');
		if (hintTrigger) {
			e.preventDefault();
			var hintItem = hintTrigger.closest('.mailpai-smtp-hints-accordion__item');
			var hintPanel = document.getElementById(hintTrigger.getAttribute('aria-controls') || '');
			if (!hintItem || !hintPanel) {
				return;
			}
			var hintOpen = hintTrigger.getAttribute('aria-expanded') === 'true';
			hintTrigger.setAttribute('aria-expanded', hintOpen ? 'false' : 'true');
			hintItem.classList.toggle('is-open', !hintOpen);
			hintPanel.hidden = hintOpen;
			return;
		}

		var logDetailTrigger = e.target.closest('.mailpai-smtp-log-detail__trigger');
		if (logDetailTrigger) {
			e.preventDefault();
			var logDetailItem = logDetailTrigger.closest('.mailpai-smtp-log-detail__group');
			var logDetailPanel = document.getElementById(logDetailTrigger.getAttribute('aria-controls') || '');
			if (!logDetailItem || !logDetailPanel) {
				return;
			}
			var logDetailOpen = logDetailTrigger.getAttribute('aria-expanded') === 'true';
			logDetailTrigger.setAttribute('aria-expanded', logDetailOpen ? 'false' : 'true');
			logDetailItem.classList.toggle('is-open', !logDetailOpen);
			logDetailPanel.hidden = logDetailOpen;
			if (!logDetailOpen) {
				window.requestAnimationFrame(function () {
					resizeLogBodyFrames(logDetailPanel);
					window.setTimeout(function () {
						resizeLogBodyFrames(logDetailPanel);
					}, 100);
				});
			}
			return;
		}

		var copyBtn = e.target.closest('.mailpai-smtp-oauth-copy');
		if (copyBtn) {
			e.preventDefault();
			var targetId = copyBtn.getAttribute('data-copy-target');
			var input = targetId ? document.getElementById(targetId) : null;
			if (!input) {
				return;
			}
			input.select();
			input.setSelectionRange(0, input.value.length);
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(input.value);
			} else {
				document.execCommand('copy');
			}
			copyBtn.textContent = copyBtn.getAttribute('data-copied-label') || 'Copied';
			window.setTimeout(function () {
				copyBtn.textContent = copyBtn.getAttribute('data-default-label') || 'Copy';
			}, 1500);
		}

		var testBtn = e.target.closest('.mailpai-smtp-test-btn');
		if (testBtn) {
			e.preventDefault();
			openTestModal(
				testBtn.getAttribute('data-connection-id'),
				testBtn.closest('.mailpai-smtp-conn-card'),
				testBtn.getAttribute('data-connection-title') || ''
			);
			return;
		}

		if (e.target.closest('.mailpai-smtp-test-modal__submit')) {
			e.preventDefault();
			var testModal = getTestModal();
			var connectionId = testModal ? testModal.dataset.connectionId : '';
			var emailInput = getTestEmailInput();
			var to = emailInput ? String(emailInput.value || '').trim() : '';
			if (!isValidEmail(to)) {
				showTestModalError((cfg.i18n && cfg.i18n.invalidEmail) || 'Enter a valid email address.');
				if (emailInput) {
					emailInput.focus();
				}
				return;
			}
			showTestModalError('');
			runConnectionTest(connectionId, to, testModalState.card);
			return;
		}

		if (e.target.closest('.mailpai-smtp-test-modal__cancel') || e.target.closest('.mailpai-smtp-test-modal__close')) {
			e.preventDefault();
			closeTestModal();
			return;
		}

		var testModalBackdrop = getTestModal();
		if (testModalBackdrop && !testModalBackdrop.hidden && e.target === testModalBackdrop) {
			closeTestModal();
			return;
		}

		var viewBtn = e.target.closest('.mailpai-smtp-log-view');
		if (viewBtn) {
			loadLogDetail(viewBtn.getAttribute('data-log-id'), true);
			return;
		}

		var logNavBtn = e.target.closest('.mailpai-smtp-log-modal__nav');
		if (logNavBtn && !logNavBtn.disabled && logNavBtn.dataset.logId) {
			loadLogDetail(logNavBtn.dataset.logId, false);
			return;
		}

		var retryBtn = e.target.closest('.mailpai-smtp-log-retry');
		if (retryBtn) {
			retryBtn.disabled = true;
			post('mailpai_smtp_retry_log', { log_id: retryBtn.getAttribute('data-log-id') })
				.then(function (res) {
					window.alert((res.data && res.data.message) || (res.success ? 'OK' : 'Error'));
					if (res.success) {
						window.location.reload();
					}
				})
				.finally(function () {
					retryBtn.disabled = false;
				});
		}

		if (e.target.closest('.mailpai-smtp-log-modal__close')) {
			closeLogModal();
			return;
		}

		var modal = getLogModal();
		if (modal && !modal.hidden && e.target === modal) {
			closeLogModal();
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			closeTestModal();
			closeLogModal();
			closeProviderPanel();
			return;
		}

		var testModal = getTestModal();
		if (testModal && !testModal.hidden && e.key === 'Enter' && e.target === getTestEmailInput()) {
			e.preventDefault();
			var submitBtn = testModal.querySelector('.mailpai-smtp-test-modal__submit');
			if (submitBtn) {
				submitBtn.click();
			}
			return;
		}

		var modal = getLogModal();
		if (!modal || modal.hidden) {
			return;
		}

		if (e.key === 'ArrowLeft') {
			var prevBtn = modal.querySelector('.mailpai-smtp-log-modal__nav--prev');
			if (prevBtn && !prevBtn.disabled && prevBtn.dataset.logId) {
				e.preventDefault();
				loadLogDetail(prevBtn.dataset.logId, false);
			}
		}

		if (e.key === 'ArrowRight') {
			var nextBtn = modal.querySelector('.mailpai-smtp-log-modal__nav--next');
			if (nextBtn && !nextBtn.disabled && nextBtn.dataset.logId) {
				e.preventDefault();
				loadLogDetail(nextBtn.dataset.logId, false);
			}
		}
	});

	document.addEventListener('change', function (e) {
		if (e.target.name === 'mailpai_smtp_secrets_storage') {
			syncSecretsPanes(e.target.closest('form'), e.target.value === 'wp_config');
		}
	});

	function syncSecretsPanes(form, wp) {
		if (!form) {
			return;
		}
		var panes = form.querySelectorAll('.mailpai-smtp-secrets-pane');
		panes.forEach(function (pane, i) {
			var hidden = wp ? i === 0 : i === 1;
			pane.hidden = hidden;
			pane.querySelectorAll('input, select, textarea').forEach(function (field) {
				field.disabled = hidden;
			});
		});
		form.querySelectorAll('.mailpai-smtp-secrets-tab').forEach(function (tab) {
			var input = tab.querySelector('input[name="mailpai_smtp_secrets_storage"]');
			tab.classList.toggle('is-active', input && input.checked);
		});
	}

	document.querySelectorAll('.mailpai-smtp-conn-form').forEach(function (form) {
		var checked = form.querySelector('input[name="mailpai_smtp_secrets_storage"]:checked');
		syncSecretsPanes(form, checked && checked.value === 'wp_config');
	});

	function syncRouteMode(form) {
		if (!form || !form.classList.contains('mailpai-smtp-routes-form')) {
			return;
		}
		var modeInput = form.querySelector('input[name="route_mode"]:checked');
		var isOne = modeInput && modeInput.value === 'one';
		form.querySelectorAll('.mailpai-smtp-routes-mode__option').forEach(function (option) {
			var panel = option.querySelector('[data-route-mode-panel]');
			if (!panel) {
				return;
			}
			var active = (panel.getAttribute('data-route-mode-panel') === 'one') === isOne;
			option.classList.toggle('is-inactive', !active);
		});
		form.querySelectorAll('[data-route-mode-panel]').forEach(function (panel) {
			var active = (panel.getAttribute('data-route-mode-panel') === 'one') === isOne;
			panel.querySelectorAll('input, select, textarea').forEach(function (field) {
				if (field.name === 'route_mode') {
					return;
				}
				field.disabled = !active;
			});
		});
	}

	function syncRouteSelectLogo(select) {
		if (!select) {
			return;
		}
		var wrap = select.closest('.mailpai-smtp-route-select');
		if (!wrap) {
			return;
		}
		var img = wrap.querySelector('.mailpai-smtp-route-select__logo');
		if (!img) {
			return;
		}
		var opt = select.options[select.selectedIndex];
		var logo = opt && opt.getAttribute('data-logo');
		if (logo) {
			img.src = logo;
			img.hidden = false;
			select.classList.add('has-logo');
		} else {
			img.hidden = true;
			img.removeAttribute('src');
			select.classList.remove('has-logo');
		}
	}

	document.querySelectorAll('.mailpai-smtp-route-row__select').forEach(function (select) {
		syncRouteSelectLogo(select);
		select.addEventListener('change', function () {
			syncRouteSelectLogo(select);
		});
	});

	document.querySelectorAll('.mailpai-smtp-routes-form').forEach(function (form) {
		syncRouteMode(form);
		form.addEventListener('change', function (e) {
			if (e.target.name === 'route_mode') {
				syncRouteMode(form);
			}
		});
		form.addEventListener('submit', function () {
			form.querySelectorAll('[data-route-mode-panel] input, [data-route-mode-panel] select, [data-route-mode-panel] textarea').forEach(function (field) {
				field.disabled = false;
			});
		});
	});

	var selectAll = document.getElementById('mailpai-smtp-log-select-all');
	if (selectAll) {
		selectAll.addEventListener('change', function () {
			document.querySelectorAll('input[name="log_ids[]"]').forEach(function (cb) {
				cb.checked = selectAll.checked;
			});
		});
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function escapeAttr(str) {
		return escapeHtml(str).replace(/'/g, '&#39;');
	}

	function wrapLogBodySrcdoc(html) {
		return (
			'<!DOCTYPE html><html><head><meta charset="utf-8">' +
			'<style>html,body{margin:0;padding:0;overflow:visible;height:auto;}</style></head><body>' +
			html +
			'</body></html>'
		);
	}

	function measureFrameHeight(frame) {
		var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
		if (!doc) {
			return 0;
		}

		var html = doc.documentElement;
		var body = doc.body;
		return Math.max(
			html ? html.scrollHeight : 0,
			html ? html.offsetHeight : 0,
			body ? body.scrollHeight : 0,
			body ? body.offsetHeight : 0
		);
	}

	function resizeLogBodyFrames(root) {
		if (!root) {
			return;
		}

		root.querySelectorAll('.mailpai-smtp-log-detail__body-frame').forEach(function (frame) {
			function resize() {
				try {
					var height = measureFrameHeight(frame);
					if (height > 0) {
						frame.style.height = height + 'px';
					}
				} catch (err) {
					frame.style.minHeight = '240px';
				}
			}

			function scheduleResize() {
				resize();
				window.setTimeout(resize, 60);
				window.setTimeout(resize, 300);
			}

			if (!frame.dataset.mailpaiResizeBound) {
				frame.dataset.mailpaiResizeBound = '1';
				frame.addEventListener('load', scheduleResize);
			}

			scheduleResize();
		});
	}

	function filterDetailRows(rows) {
		return rows.filter(function (row) {
			return row && row[1];
		});
	}

	function buildDetailSummary(rows, limit) {
		return filterDetailRows(rows)
			.slice(0, limit || 2)
			.map(function (row) {
				return String(row[1]);
			})
			.join(' · ');
	}

	function renderFieldRows(rows, modifier) {
		var html = '<dl class="mailpai-smtp-log-detail__rows' + (modifier ? ' mailpai-smtp-log-detail__rows--' + modifier : '') + '">';
		filterDetailRows(rows).forEach(function (row) {
			var valueClass = typeof row[2] === 'string' && row[2].indexOf('is-') === 0 ? row[2] : '';
			html += '<dt>' + escapeHtml(row[0]) + '</dt>';
			html += '<dd' + (valueClass ? ' class="' + valueClass + '"' : '') + '>' + escapeHtml(String(row[1])) + '</dd>';
		});
		html += '</dl>';
		return html;
	}

	function renderStaticSection(rows) {
		var visible = filterDetailRows(rows);
		if (!visible.length) {
			return '';
		}

		return '<section class="mailpai-smtp-log-detail__static">' + renderFieldRows(visible, 'static') + '</section>';
	}

	function renderAccordionSection(slug, title, summary, rows, open, bodyExtra) {
		var visible = filterDetailRows(rows);
		if (!visible.length && !bodyExtra) {
			return '';
		}

		var panelId = 'mailpai-smtp-log-detail-' + slug;
		var html = '<div class="mailpai-smtp-log-detail__group' + (open ? ' is-open' : '') + '">';
		html += '<button type="button" class="mailpai-smtp-log-detail__trigger" aria-expanded="' + (open ? 'true' : 'false') + '" aria-controls="' + panelId + '">';
		html += '<span class="mailpai-smtp-log-detail__trigger-title">' + escapeHtml(title) + '</span>';
		if (summary) {
			html += '<span class="mailpai-smtp-log-detail__trigger-summary">' + escapeHtml(summary) + '</span>';
		}
		html += '<span class="mailpai-smtp-log-detail__chevron" aria-hidden="true"></span>';
		html += '</button>';
		html += '<div id="' + panelId + '" class="mailpai-smtp-log-detail__panel"' + (open ? '' : ' hidden') + '>';
		if (visible.length) {
			html += renderFieldRows(visible);
		}
		if (bodyExtra) {
			html += bodyExtra;
		}
		html += '</div></div>';
		return html;
	}

	function renderLogDetail(log) {
		var i18n = (cfg.i18n && cfg.i18n.logDetail) || {};
		var statusClass = log.status === 'failed' ? 'mailpai-smtp-badge--failed' : 'mailpai-smtp-badge--sent';
		var html = '<div class="mailpai-smtp-log-detail">';

		html += '<div class="mailpai-smtp-log-detail__head">';
		html += '<span class="mailpai-smtp-badge ' + statusClass + '">' + escapeHtml(log.status_label || log.status || '') + '</span>';
		html += '</div>';
		html += '<h3 id="mailpai-smtp-log-modal-title" class="mailpai-smtp-log-detail__subject">' + escapeHtml(log.subject || '') + '</h3>';

		if (log.error_message) {
			html += '<div class="mailpai-smtp-log-detail__error" role="alert">' + escapeHtml(log.error_message) + '</div>';
		}

		var overviewRows = [
			[i18n.sentAt || 'Sent at', log.created_at],
			[i18n.sentAtUtc || 'UTC', log.created_at_utc],
			[i18n.status || 'Status', log.status_label, log.status === 'failed' ? 'is-failed' : 'is-sent'],
			[i18n.logId || 'Log ID', '#' + log.id, 'is-muted']
		];
		var envelopeRows = [
			[i18n.from || 'From', log.from, 'is-strong'],
			[i18n.to || 'To', log.recipient, 'is-strong'],
			[i18n.replyTo || 'Reply-To', log.reply_to],
			[i18n.cc || 'Cc', log.cc],
			[i18n.bcc || 'Bcc', log.bcc],
			[i18n.returnPath || 'Return-Path', log.return_path],
			[i18n.messageId || 'Message-ID', log.message_id, 'is-mono']
		];
		var deliveryRows = [
			[i18n.connection || 'Connection', log.connection_label, 'is-strong'],
			[i18n.provider || 'Provider', log.provider_label],
			[i18n.route || 'Route', log.route_label],
			[i18n.transport || 'Transport', log.transport_label],
			log.failover ? [i18n.failover || 'Failover', i18n.yes || 'Yes', 'is-sent'] : null,
			log.failover && log.primary_connection_label ? [i18n.primaryConnection || 'Primary connection', log.primary_connection_label, 'is-strong'] : null
		];
		html += renderStaticSection(overviewRows.concat(envelopeRows, deliveryRows));

		html += '<div class="mailpai-smtp-log-detail__groups">';

		var headerRows = filterDetailRows([
			log.content_type ? [i18n.contentType || 'Content-Type', log.content_type] : null
		]);
		var headersExtra = '';
		if (log.headers && log.headers.length) {
			headersExtra += '<pre class="mailpai-smtp-log-detail__headers">';
			log.headers.forEach(function (header) {
				headersExtra += escapeHtml(header.name + ': ' + header.value) + '\n';
			});
			headersExtra += '</pre>';
		}
		if (headerRows.length || headersExtra) {
			html += renderAccordionSection(
				'headers',
				i18n.headers || 'Headers',
				buildDetailSummary(headerRows, 1) || (log.headers && log.headers.length ? log.headers.length + ' ' + (i18n.headerCount || 'headers') : ''),
				headerRows,
				false,
				headersExtra
			);
		}

		var serverStatusExtra = '';
		if (log.server_status) {
			serverStatusExtra = '<pre class="mailpai-smtp-log-detail__server-status">' + escapeHtml(log.server_status) + '</pre>';
		} else {
			serverStatusExtra = '<p class="mailpai-smtp-log-detail__note">' + escapeHtml(i18n.serverStatusUnavailable || 'Server response was not recorded for this entry.') + '</p>';
		}
		html += renderAccordionSection(
			'server-status',
			i18n.mailServerStatus || 'Mail Server Status',
			log.server_status_summary || (log.server_status ? log.server_status.split('\n')[0] : (i18n.serverStatusUnavailable || 'Not recorded')),
			[],
			false,
			serverStatusExtra
		);

		var messageSummary = log.body_stored && log.body
			? (i18n.htmlMessage || 'HTML message preview')
			: (log.body_logging_enabled ? (i18n.bodyUnavailable || 'Body not saved') : (i18n.bodyDisabled || 'Body logging disabled'));
		var messageExtra = '';
		if (log.body_stored && log.body) {
			messageExtra += '<iframe class="mailpai-smtp-log-detail__body-frame" sandbox="allow-same-origin" scrolling="no" title="' + escapeAttr(log.subject || '') + '" srcdoc="' + escapeAttr(wrapLogBodySrcdoc(log.body)) + '"></iframe>';
		} else {
			messageExtra += '<p class="mailpai-smtp-log-detail__note">' + escapeHtml(log.body_logging_enabled ? (i18n.bodyUnavailable || 'Message body was not saved for this entry.') : (i18n.bodyDisabled || 'Email body is not stored. Enable it under Settings if you need a full message preview.')) + '</p>';
		}
		html += renderAccordionSection(
			'message',
			i18n.message || 'Message',
			messageSummary,
			[],
			false,
			messageExtra
		);

		html += '</div></div>';

		return html;
	}

	function scrollToAdminNotice() {
		var host = document.querySelector('.mailpai-smtp-app__body');
		if (!host) {
			return;
		}
		var notice = host.querySelector('.mailpai-smtp-flash-notice');
		if (notice && typeof notice.scrollIntoView === 'function') {
			notice.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scrollToAdminNotice);
	} else {
		scrollToAdminNotice();
	}
})();
