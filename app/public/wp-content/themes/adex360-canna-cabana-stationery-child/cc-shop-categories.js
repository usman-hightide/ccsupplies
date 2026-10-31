/**
 * Enhance Woo Product Categories widget into approved sidebar card.
 */
(function () {
	'use strict';

	var ICONS = {
		batteries:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="7" width="12" height="14" rx="2"/><path d="M10 7V5h4v2"/><path d="M10 12h4"/><path d="M10 16h4"/></svg>',
		'cleaning-breakroom':
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 10h8l-1 10H9L8 10z"/><path d="M10 10V7a2 2 0 0 1 2-2h0a2 2 0 0 1 2 2v3"/><path d="M12 3v2"/><path d="M9 14h6"/></svg>',
		'epson-ink':
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 12.4a2 2 0 0 0 0-2.8l-6.2-6.2a2 2 0 0 0-2.8 0L3.4 11.6a2 2 0 0 0 0 2.8l6.2 6.2a2 2 0 0 0 2.8 0z"/><circle cx="9.5" cy="9.5" r="1.2" fill="currentColor" stroke="none"/></svg>',
		'hp-ink':
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3c4 5 7 8.2 7 11.2A7 7 0 0 1 5 14.2C5 11.2 8 8 12 3z"/></svg>',
		'lexmark-ink':
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1"/><path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7.1 7.1l1.1-1.1"/></svg>',
		'office-supplies':
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5 12 4l9 4.5v9L12 22 3 17.5v-9z"/><path d="M12 12v10"/><path d="M3 8.5 12 12l9-3.5"/></svg>',
		other:
			'<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="6" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="18" cy="12" r="1.6"/></svg>',
		'paper-products':
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h6"/></svg>',
		uncategorized:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 2.4 4.9 5.4.8-3.9 3.8.9 5.4L12 15.9 7.2 18l.9-5.4L4.2 8.7l5.4-.8L12 3z"/></svg>',
		_default:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16v12H4z"/><path d="M8 7V5h8v2"/></svg>'
	};

	function slugFromHref(href) {
		try {
			var path = (href || '').split('?')[0].replace(/\/+$/, '');
			var parts = path.split('/');
			return parts[parts.length - 1] || '';
		} catch (e) {
			return '';
		}
	}

	function titleCaseLabel(raw) {
		var s = String(raw || '').trim();
		if (!s) {
			return s;
		}
		s = s.toLowerCase().replace(/\b([a-z])/g, function (m) {
			return m.toUpperCase();
		});
		s = s.replace(/\bHp\b/g, 'HP').replace(/\bIbm\b/g, 'IBM');
		return s;
	}

	function iconForSlug(slug) {
		return ICONS[slug] || ICONS._default;
	}

	function enhance(root) {
		if (!root || root.classList.contains('cc-cat-ready')) {
			return;
		}
		root.classList.add('cc-cat-ready');

		var oldTitle = root.querySelector(':scope > h5, :scope > .widget-title');
		var titleText = oldTitle ? oldTitle.textContent.trim() : 'Product Categories';

		var head = document.createElement('button');
		head.type = 'button';
		head.className = 'cc-cat-head';
		head.setAttribute('aria-expanded', 'true');
		head.innerHTML =
			'<span class="cc-cat-head__icon" aria-hidden="true"><span></span><span></span><span></span><span></span></span>' +
			'<span class="cc-cat-head__label"></span>' +
			'<span class="cc-cat-head__chev" aria-hidden="true"></span>';
		head.querySelector('.cc-cat-head__label').textContent = titleText;

		head.addEventListener('click', function () {
			var collapsed = root.classList.toggle('is-collapsed');
			head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
		});

		root.insertBefore(head, root.firstChild);

		var items = root.querySelectorAll('ul.product-categories > li.cat-item');
		items.forEach(function (li) {
			var a = li.querySelector(':scope > a');
			if (!a || a.querySelector('.cc-cat-ico')) {
				return;
			}
			var slug = slugFromHref(a.getAttribute('href'));
			li.setAttribute('data-cc-cat', slug);

			var ico = document.createElement('span');
			ico.className = 'cc-cat-ico';
			ico.setAttribute('aria-hidden', 'true');
			ico.innerHTML = iconForSlug(slug);

			var label = document.createElement('span');
			label.className = 'cc-cat-label';
			label.textContent = titleCaseLabel(a.textContent);

			a.textContent = '';
			a.appendChild(ico);
			a.appendChild(label);

			if (!li.querySelector(':scope > .cc-cat-arrow')) {
				var arrow = document.createElement('span');
				arrow.className = 'cc-cat-arrow';
				arrow.setAttribute('aria-hidden', 'true');
				li.appendChild(arrow);
			}
		});

		if (!root.querySelector('.cc-cat-foot')) {
			var foot = document.createElement('div');
			foot.className = 'cc-cat-foot';
			foot.innerHTML =
				'<span class="cc-cat-foot__rule" aria-hidden="true"></span>' +
				'<p class="cc-cat-foot__text">Quality products for your workspace</p>';
			root.appendChild(foot);
		}
	}

	function run() {
		document.querySelectorAll('#shop-sidebar-cat .widget_product_categories').forEach(enhance);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
})();
