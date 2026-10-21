/* Product Sync Feeds admin. No build step, no dependencies. */
( function () {
	'use strict';

	var cfg = window.psfAdmin || {};
	var i18n = cfg.i18n || {};

	function $( sel, root ) {
		return ( root || document ).querySelector( sel );
	}
	function $$( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	}

	// Confirm links (delete, reset, regenerate).
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '[data-psf-confirm]' );
		if ( link && ! window.confirm( link.getAttribute( 'data-psf-confirm' ) || i18n.confirm ) ) {
			e.preventDefault();
		}
	} );

	var form = $( '#psf-feed-form' );
	if ( form ) {
		initTabs();
		initRows();
		initFormatToggles();
		initIntervals();
		initDefaults();
		initUploadTest();
	}

	var list = $( '[data-psf-poll]' );
	if ( list ) {
		initPolling();
	}

	function initTabs() {
		var tabs = $$( '.psf-tabs .nav-tab' );
		var tabField = $( '#psf-tab-field' );

		function show( id ) {
			if ( ! $( '#psf-panel-' + id ) ) {
				id = 'general';
			}
			tabs.forEach( function ( t ) {
				t.classList.toggle( 'nav-tab-active', t.getAttribute( 'href' ) === '#' + id );
			} );
			$$( '.psf-panel' ).forEach( function ( p ) {
				p.hidden = p.id !== 'psf-panel-' + id;
			} );
			tabField.value = id;
		}

		tabs.forEach( function ( t ) {
			t.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var id = t.getAttribute( 'href' ).slice( 1 );
				history.replaceState( null, '', '#' + id );
				show( id );
			} );
		} );

		// Invalid required fields inside hidden tabs block submit silently: reveal them.
		form.addEventListener(
			'invalid',
			function ( e ) {
				var panel = e.target.closest( '.psf-panel' );
				if ( panel && panel.hidden ) {
					show( panel.id.replace( 'psf-panel-', '' ) );
				}
			},
			true
		);

		show( location.hash.slice( 1 ) || 'general' );
	}

	function nextIndex( tbody ) {
		var max = -1;
		$$( '[name]', tbody ).forEach( function ( el ) {
			var m = el.name.match( /\[(\d+)\]/g );
			if ( m ) {
				var n = parseInt( m[ m.length - 1 ].replace( /\D/g, '' ), 10 );
				max = Math.max( max, n );
			}
		} );
		return max + 1;
	}

	function addRow( tableId, templateId, values ) {
		var tbody = $( '#' + tableId + ' tbody' );
		var html = $( '#' + templateId ).innerHTML.replace( /__i__/g, String( nextIndex( tbody ) ) );
		var holder = document.createElement( 'tbody' );
		holder.innerHTML = html.trim();
		var row = holder.firstElementChild;
		if ( values ) {
			Object.keys( values ).forEach( function ( key ) {
				var input = row.querySelector( '[name$="[' + key + ']"], [name$="[' + key + '][]"]' );
				if ( ! input ) {
					return;
				}
				if ( input.multiple ) {
					$$( 'option', input ).forEach( function ( o ) {
						o.selected = ( values[ key ] || [] ).indexOf( o.value ) !== -1;
					} );
				} else {
					input.value = values[ key ];
				}
			} );
		}
		tbody.appendChild( row );
		return row;
	}

	function initRows() {
		$$( '.psf-add' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var row = addRow( btn.dataset.table, btn.dataset.template );
				var first = row.querySelector( 'input' );
				if ( first ) {
					first.focus();
				}
			} );
		} );

		form.addEventListener( 'click', function ( e ) {
			var row = e.target.closest( '.psf-row' );
			if ( ! row ) {
				return;
			}
			if ( e.target.closest( '.psf-remove' ) ) {
				row.remove();
			} else if ( e.target.closest( '.psf-up' ) && row.previousElementSibling ) {
				row.parentNode.insertBefore( row, row.previousElementSibling );
			} else if ( e.target.closest( '.psf-down' ) && row.nextElementSibling ) {
				row.parentNode.insertBefore( row.nextElementSibling, row );
			}
		} );
	}

	function initFormatToggles() {
		var format = $( '#psf-format' );
		var channel = $( '#psf-channel' );
		var protocol = $( '#psf-protocol' );

		function apply() {
			var f = format.value;
			var isXml = f === 'xml';
			var isCsv = f === 'csv' || f === 'tsv';
			$$( '.psf-show-xml' ).forEach( function ( el ) {
				var needsCustom = el.classList.contains( 'psf-show-custom' );
				el.hidden = ! isXml || ( needsCustom && channel.value !== 'custom' );
			} );
			$$( '.psf-show-csv' ).forEach( function ( el ) {
				el.hidden = ! isCsv;
			} );
			var p = protocol.value;
			$$( '.psf-show-ftp' ).forEach( function ( el ) {
				el.hidden = p === 'sftp';
			} );
			$$( '.psf-show-sftp' ).forEach( function ( el ) {
				el.hidden = p !== 'sftp';
			} );

			// Only offer formats the channel accepts.
			var info = ( cfg.channels || {} )[ channel.value ];
			if ( info ) {
				$$( 'option', format ).forEach( function ( o ) {
					o.disabled = info.formats.indexOf( o.value ) === -1;
				} );
				if ( info.formats.indexOf( format.value ) === -1 ) {
					format.value = info.formats[ 0 ];
					apply();
				}
			}
		}

		[ format, channel, protocol ].forEach( function ( el ) {
			el.addEventListener( 'change', apply );
		} );
		apply();
	}

	function initIntervals() {
		$$( '.psf-interval' ).forEach( function ( wrap ) {
			var preset = $( '.psf-interval-preset', wrap );
			var minutes = $( '.psf-interval-minutes', wrap );
			var customWrap = $( '.psf-interval-custom', wrap );
			function sync() {
				var custom = preset.value === 'custom';
				customWrap.hidden = ! custom;
				if ( ! custom ) {
					minutes.value = preset.value;
				}
			}
			preset.addEventListener( 'change', sync );
			sync();
		} );
	}

	function initDefaults() {
		var btn = $( '#psf-load-defaults' );
		btn.addEventListener( 'click', function () {
			var info = ( cfg.channels || {} )[ $( '#psf-channel' ).value ];
			if ( ! info || ! window.confirm( i18n.loadDefaults ) ) {
				return;
			}
			var tbody = $( '#psf-mapping tbody' );
			tbody.innerHTML = '';
			info.mapping.forEach( function ( m ) {
				addRow( 'psf-mapping', 'psf-mapping-template', m );
			} );
			var tab = $( '.psf-tabs a[href="#mapping"]' );
			if ( tab ) {
				tab.click();
			}
		} );
	}

	function initUploadTest() {
		var btn = $( '#psf-test-upload' );
		var out = $( '#psf-test-result' );
		btn.addEventListener( 'click', function () {
			var data = new FormData();
			data.append( 'action', 'psf_test_upload' );
			data.append( 'nonce', cfg.nonce );
			data.append( 'feed_id', form.querySelector( '[name="feed_id"]' ).value );
			$$( '[name^="config[upload]"]', form ).forEach( function ( el ) {
				if ( ( el.type === 'checkbox' || el.type === 'radio' ) && ! el.checked ) {
					return;
				}
				data.append( el.name.replace( /^config\[upload\]/, 'upload' ), el.value );
			} );
			out.className = '';
			out.textContent = i18n.testing;
			btn.disabled = true;
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					out.className = res.success ? 'psf-ok' : 'psf-error-text';
					out.textContent = ( res.data && res.data.message ) || '';
				} )
				.catch( function ( err ) {
					out.className = 'psf-error-text';
					out.textContent = String( err );
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	}

	function initPolling() {
		var wasRunning = $$( '.psf-progress' ).length > 0;
		if ( ! wasRunning ) {
			return;
		}
		var timer = setInterval( function () {
			var data = new FormData();
			data.append( 'action', 'psf_status' );
			data.append( 'nonce', cfg.nonce );
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( ! res.success ) {
						return;
					}
					var running = false;
					Object.keys( res.data ).forEach( function ( id ) {
						var run = res.data[ id ];
						var cell = $( 'tr[data-feed="' + id + '"] .psf-progress' );
						if ( run ) {
							running = true;
							if ( cell ) {
								cell.textContent = run.type.charAt( 0 ).toUpperCase() + run.type.slice( 1 ) + ' run: ' + run.processed + ' / ' + run.total;
							}
						} else if ( cell ) {
							// Finished: reload once to show files and buttons.
							clearInterval( timer );
							location.reload();
						}
					} );
					if ( ! running ) {
						clearInterval( timer );
					}
				} );
		}, 5000 );
	}
} )();
