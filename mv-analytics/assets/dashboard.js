/* ============================================================
   MULTIVEGETAL — Analytics Dashboard
   Sem dependências externas, mesma regra dos demais widgets
   (ver assets/grid.js). Os gráficos são SVG gerado aqui.

   Fonte dos dados: window.MV_AN_DATA — injetado por
   wp_localize_script no WordPress, ou pelo <script> do
   preview.html quando roda fora do WP.
   ============================================================ */
( function () {
	'use strict';

	var SVGNS = 'http://www.w3.org/2000/svg';

	/* ── Formatação (pt-BR) ─────────────────────────────────── */

	var brl = new Intl.NumberFormat( 'pt-BR', {
		style: 'currency', currency: 'BRL', maximumFractionDigits: 0
	} );
	var brlCents = new Intl.NumberFormat( 'pt-BR', {
		style: 'currency', currency: 'BRL', minimumFractionDigits: 2
	} );
	var num = new Intl.NumberFormat( 'pt-BR' );

	function fmtMoney( v )  { return brl.format( v || 0 ); }
	function fmtMoney2( v ) { return brlCents.format( v || 0 ); }
	function fmtNum( v )    { return num.format( v || 0 ); }
	function fmtPct( v, d ) { return ( v || 0 ).toFixed( d == null ? 1 : d ).replace( '.', ',' ) + '%'; }

	/* Abrevia no eixo para não colidir rótulo: 12,4 mil / 1,2 mi */
	function fmtAxis( v ) {
		var a = Math.abs( v );
		if ( a >= 1e6 ) return ( v / 1e6 ).toFixed( 1 ).replace( '.', ',' ) + ' mi';
		if ( a >= 1e3 ) return ( v / 1e3 ).toFixed( a >= 1e4 ? 0 : 1 ).replace( '.', ',' ) + ' mil';
		return num.format( v );
	}

	/* ── Helpers DOM/SVG ────────────────────────────────────── */

	function el( tag, attrs, text ) {
		var n = document.createElement( tag );
		for ( var k in attrs ) if ( attrs[ k ] != null ) n.setAttribute( k, attrs[ k ] );
		if ( text != null ) n.textContent = text;
		return n;
	}

	function svgEl( tag, attrs ) {
		var n = document.createElementNS( SVGNS, tag );
		for ( var k in attrs ) if ( attrs[ k ] != null ) n.setAttribute( k, attrs[ k ] );
		return n;
	}

	/* Retângulo com o topo arredondado em 4px, ancorado na base.
	   É a "ponta de dado" arredondada: só a extremidade livre
	   recebe raio; o lado colado na linha de base fica reto. */
	function topRoundedPath( x, y, w, h, r ) {
		if ( h <= 0 ) return '';
		r = Math.min( r, w / 2, h );
		return 'M' + x + ',' + ( y + h ) +
			'L' + x + ',' + ( y + r ) +
			'Q' + x + ',' + y + ' ' + ( x + r ) + ',' + y +
			'L' + ( x + w - r ) + ',' + y +
			'Q' + ( x + w ) + ',' + y + ' ' + ( x + w ) + ',' + ( y + r ) +
			'L' + ( x + w ) + ',' + ( y + h ) + 'Z';
	}

	/* Passo "redondo" imediatamente acima de v. */
	function niceStep( v ) {
		if ( v <= 0 ) return 1;
		var exp  = Math.pow( 10, Math.floor( Math.log( v ) / Math.LN10 ) );
		var frac = v / exp;
		var step = frac <= 1 ? 1 : frac <= 2 ? 2 : frac <= 2.5 ? 2.5 : frac <= 5 ? 5 : 10;
		return step * exp;
	}

	/* Topo do eixo + passo das linhas de grade.
	   Deriva o passo do alvo de ~4 divisões e só então arredonda o topo
	   para cima: assim cada rótulo cai num número limpo e o gráfico não
	   sobra altura vazia (arredondar o topo primeiro faria um pico de
	   143 mil virar um eixo de 200 mil). */
	function axisScale( maxValue ) {
		if ( maxValue <= 0 ) return { max: 1, step: 1, count: 1 };
		var step = niceStep( maxValue / 4 );
		var max  = Math.ceil( maxValue / step ) * step;
		return { max: max, step: step, count: Math.round( max / step ) };
	}

	/* Gráficos que precisam ser redesenhados quando a largura muda
	   (troca de aba ou resize da janela). Um SVG com viewBox fixo
	   encolheria o texto junto com o desenho: a 390px os rótulos dos
	   meses caíam para ~4px. Redesenhar na largura real mantém a
	   tipografia no tamanho declarado. */
	var redrawers = [];

	function onResize() {
		var t;
		return function () {
			clearTimeout( t );
			t = setTimeout( function () {
				redrawers.forEach( function ( fn ) { fn(); } );
			}, 150 );
		};
	}
	window.addEventListener( 'resize', onResize() );

	/* ============================================================
	   GRÁFICO 1 — Barras empilhadas: receita por mês,
	   novo vs. recorrente. Duas séries => legenda obrigatória.
	   ============================================================ */
	function stackedBars( host, rows, opts ) {
		opts = opts || {};
		if ( ! rows || ! rows.length ) {
			host.appendChild( el( 'div', { class: 'mv-an-empty' }, 'Sem dados no período selecionado.' ) );
			return;
		}

		var tip = el( 'div', { class: 'mv-an-tip' } );
		var st  = {};   /* geometria corrente, compartilhada com o hover */

		function draw() {
			var prev = host.querySelector( 'svg' );
			if ( prev ) host.removeChild( prev );
			drawInto( host, rows, opts, st, tip );
		}

		draw();
		host.appendChild( tip );
		redrawers.push( draw );
	}

	function drawInto( host, rows, opts, st, tip ) {
		/* Painel oculto tem clientWidth 0: cai no padrão e é redesenhado
		   quando a aba abre (initTabs dispara os redrawers). */
		var W = Math.max( 320, Math.round( host.clientWidth || 900 ) );
		var H = W < 560 ? 240 : 320;
		var pad = { t: 16, r: 12, b: 34, l: W < 560 ? 48 : 62 };
		var iw = W - pad.l - pad.r;
		var ih = H - pad.t - pad.b;

		var totals = rows.map( function ( r ) { return ( r.s1 || 0 ) + ( r.s2 || 0 ); } );
		var scale  = axisScale( Math.max.apply( null, totals ) );
		var max    = scale.max;

		var svg = svgEl( 'svg', {
			viewBox: '0 0 ' + W + ' ' + H,
			role: 'img',
			'aria-label': opts.alt || 'Receita por mês, separada entre clientes novos e recorrentes.'
		} );

		var y = function ( v ) { return pad.t + ih - ( v / max ) * ih; };

		/* Grade recessiva + rótulos do eixo. */
		var gGrid = svgEl( 'g', { class: 'mv-an-chart__grid' } );
		for ( var i = 0; i <= scale.count; i++ ) {
			var v  = scale.step * i;
			var yy = y( v );
			gGrid.appendChild( svgEl( 'line', { x1: pad.l, x2: pad.l + iw, y1: yy, y2: yy } ) );
			var t = svgEl( 'text', { x: pad.l - 10, y: yy + 4, 'text-anchor': 'end', class: 'mv-an-chart__tick' } );
			t.textContent = fmtAxis( v );
			gGrid.appendChild( t );
		}
		svg.appendChild( gGrid );

		/* Barras. */
		var slot  = iw / rows.length;
		var bw    = Math.min( 46, slot * 0.62 );
		var gBars = svgEl( 'g', { class: 'mv-an-stack' } );
		var gHits = svgEl( 'g' );

		/* Rótulos do eixo X só cabem a cada ~38px; abaixo disso pula meses
		   em vez de deixar o texto colidir. A contagem parte do ÚLTIMO mês
		   para trás: assim o mês corrente — o que o leitor procura primeiro —
		   sempre aparece, sem precisar ser forçado por cima do vizinho. */
		var everyN = Math.max( 1, Math.ceil( 38 / slot ) );
		var last   = rows.length - 1;

		rows.forEach( function ( r, idx ) {
			var cx  = pad.l + slot * idx + slot / 2;
			var x   = cx - bw / 2;
			var s1  = r.s1 || 0, s2 = r.s2 || 0;
			var h1  = ( s1 / max ) * ih;
			var h2  = ( s2 / max ) * ih;
			var y1  = pad.t + ih - h1;          /* base: recorrente */
			var y2  = y1 - h2;                  /* topo: novo       */

			/* Segmento de baixo: cantos retos (encosta na base). */
			if ( h1 > 0 ) {
				gBars.appendChild( svgEl( 'rect', {
					x: x, y: y1, width: bw, height: h1,
					class: 'mv-an-mark mv-an-mark--s1', 'data-col': idx
				} ) );
			}
			/* Segmento de cima: ponta arredondada em 4px. */
			if ( h2 > 0 ) {
				gBars.appendChild( svgEl( 'path', {
					d: topRoundedPath( x, y2, bw, h2, 4 ),
					class: 'mv-an-mark mv-an-mark--s2', 'data-col': idx
				} ) );
			}

			/* Rótulo do mês. */
			if ( ( last - idx ) % everyN === 0 ) {
				var lb = svgEl( 'text', { x: cx, y: H - 12, 'text-anchor': 'middle', class: 'mv-an-chart__tick' } );
				lb.textContent = r.label;
				svg.appendChild( lb );
			}

			/* Alvo de hover: coluna inteira, bem maior que a marca. */
			gHits.appendChild( svgEl( 'rect', {
				x: pad.l + slot * idx, y: pad.t, width: slot, height: ih,
				class: 'mv-an-chart__hit', 'data-col': idx
			} ) );
		} );

		svg.appendChild( gBars );

		/* Linha de base. */
		var gBase = svgEl( 'g', { class: 'mv-an-chart__base' } );
		gBase.appendChild( svgEl( 'line', { x1: pad.l, x2: pad.l + iw, y1: pad.t + ih, y2: pad.t + ih } ) );
		svg.appendChild( gBase );
		svg.appendChild( gHits );

		host.appendChild( svg );

		/* Camada de hover — o tooltip é criado uma vez por gráfico e
		   sobrevive aos redesenhos; só a geometria é recalculada. */
		function show( idx, evt ) {
			var r = rows[ idx ];
			var total = ( r.s1 || 0 ) + ( r.s2 || 0 );
			var pctNovo = total ? ( r.s2 / total ) * 100 : 0;
			tip.innerHTML = '';
			tip.appendChild( el( 'div', { class: 'mv-an-tip__title' }, r.label ) );
			[
				[ 'var(--mv-s2)', opts.s2Label || 'Novos', fmtMoney( r.s2 ) ],
				[ 'var(--mv-s1)', opts.s1Label || 'Recorrentes', fmtMoney( r.s1 ) ]
			].forEach( function ( row ) {
				var line = el( 'div', { class: 'mv-an-tip__row' } );
				var key  = el( 'span', { class: 'mv-an-tip__key' } );
				key.appendChild( el( 'i', { class: 'mv-an-tip__dot', style: 'background:' + row[ 0 ] } ) );
				key.appendChild( document.createTextNode( row[ 1 ] ) );
				line.appendChild( key );
				line.appendChild( el( 'span', { class: 'mv-an-tip__val' }, row[ 2 ] ) );
				tip.appendChild( line );
			} );
			var tot = el( 'div', { class: 'mv-an-tip__row' } );
			tot.appendChild( el( 'span', { class: 'mv-an-tip__key' }, 'Total' ) );
			tot.appendChild( el( 'span', { class: 'mv-an-tip__val' }, fmtMoney( total ) ) );
			tip.appendChild( tot );
			var sh = el( 'div', { class: 'mv-an-tip__row' } );
			sh.appendChild( el( 'span', { class: 'mv-an-tip__key' }, '% de novos' ) );
			sh.appendChild( el( 'span', { class: 'mv-an-tip__val' }, fmtPct( pctNovo ) ) );
			tip.appendChild( sh );

			var box = host.getBoundingClientRect();
			var cx  = ( pad.l + slot * idx + slot / 2 ) / W * box.width;
			tip.style.left = cx + 'px';
			tip.style.top  = Math.max( 0, ( evt.clientY - box.top ) - 16 ) + 'px';
			tip.classList.add( 'is-on' );

			host.classList.add( 'is-hovering' );
			Array.prototype.forEach.call( svg.querySelectorAll( '.mv-an-mark' ), function ( m ) {
				m.classList.toggle( 'is-active', m.getAttribute( 'data-col' ) === String( idx ) );
			} );
		}

		function hide() {
			tip.classList.remove( 'is-on' );
			host.classList.remove( 'is-hovering' );
			Array.prototype.forEach.call( svg.querySelectorAll( '.mv-an-mark' ), function ( m ) {
				m.classList.remove( 'is-active' );
			} );
		}

		gHits.addEventListener( 'mousemove', function ( e ) {
			var t = e.target.getAttribute && e.target.getAttribute( 'data-col' );
			if ( t != null ) show( +t, e );
		} );
		gHits.addEventListener( 'mouseleave', hide );
		host.addEventListener( 'mouseleave', hide );
	}

	/* ============================================================
	   GRÁFICO 2 — Barras horizontais de série única.
	   Nominal (produto, categoria): todas no mesmo tom.
	   Ordinal (segmento de ciclo de vida): rampa de uma matiz.
	   ============================================================ */
	function hBars( host, rows, opts ) {
		opts = opts || {};
		if ( ! rows || ! rows.length ) {
			host.appendChild( el( 'div', { class: 'mv-an-empty' }, 'Sem dados no período selecionado.' ) );
			return;
		}
		var max  = Math.max.apply( null, rows.map( function ( r ) { return r.value || 0; } ) ) || 1;
		var wrap = el( 'div', { class: 'mv-an-bars' } );

		rows.forEach( function ( r, i ) {
			var bar = el( 'div', { class: 'mv-an-bar' } );
			bar.appendChild( el( 'span', { class: 'mv-an-bar__label', title: r.label }, r.label ) );
			bar.appendChild( el( 'span', { class: 'mv-an-bar__value' },
				opts.format ? opts.format( r ) : fmtMoney( r.value ) ) );

			var track = el( 'div', { class: 'mv-an-bar__track' } );
			/* Ordinal só quando trocar a ordem mudaria o sentido.
			   'desc' quando a sequência também é uma magnitude decrescente
			   (curva ABC): aí o primeiro item tem de ser o tom mais escuro,
			   senão a classe que concentra a receita sai como a mais pálida.
			   Ciclo de vida do cliente não é magnitude, então segue crescente. */
			var stepIdx = opts.ordinal === 'desc'
				? Math.max( 1, 4 - i )
				: Math.min( 5, i + 1 );
			var tone = opts.ordinal ? ' mv-an-bar__fill--r' + stepIdx : '';
			var fill  = el( 'div', { class: 'mv-an-bar__fill' + tone } );
			fill.style.width = ( ( r.value || 0 ) / max * 100 ).toFixed( 2 ) + '%';
			track.appendChild( fill );
			bar.appendChild( track );
			wrap.appendChild( bar );
		} );

		host.appendChild( wrap );
	}

	/* ============================================================
	   HEATMAP DE COORTE — rampa ordinal, 5 passos.
	   ============================================================ */
	function cohort( host, rows ) {
		if ( ! rows || ! rows.length ) {
			host.appendChild( el( 'div', { class: 'mv-an-empty' }, 'Sem coortes suficientes no período.' ) );
			return;
		}
		var wrap  = el( 'div', { class: 'mv-an-tablewrap' } );
		var table = el( 'table', { class: 'mv-an-cohort' } );

		var thead = el( 'thead' );
		var htr   = el( 'tr' );
		[ 'Mês da 1ª compra', 'Clientes', '30 dias', '60 dias', '90 dias' ].forEach( function ( h, i ) {
			htr.appendChild( el( 'th', { scope: 'col' }, h ) );
		} );
		thead.appendChild( htr );
		table.appendChild( thead );

		var step = function ( pct ) {
			if ( pct <= 0 ) return '';
			if ( pct < 8 )  return 'r1';
			if ( pct < 16 ) return 'r2';
			if ( pct < 26 ) return 'r3';
			if ( pct < 38 ) return 'r4';
			return 'r5';
		};

		var tbody = el( 'tbody' );
		rows.forEach( function ( r ) {
			var tr = el( 'tr' );
			tr.appendChild( el( 'th', { scope: 'row' }, r.label ) );
			tr.appendChild( el( 'td', { class: 'is-count' }, fmtNum( r.size ) ) );
			[ r.d30, r.d60, r.d90 ].forEach( function ( p ) {
				tr.appendChild( el( 'td', { class: step( p ) }, fmtPct( p, 0 ) ) );
			} );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		host.appendChild( wrap );

		/* Legenda da escala — a rampa precisa ser lida como ordem. */
		var sc = el( 'div', { class: 'mv-an-scale' } );
		sc.appendChild( el( 'span', {}, 'Menor recompra' ) );
		var steps = el( 'span', { class: 'mv-an-scale__steps' } );
		[ 1, 2, 3, 4, 5 ].forEach( function ( n ) {
			steps.appendChild( el( 'i', { style: 'background:var(--mv-r' + n + ')' } ) );
		} );
		sc.appendChild( steps );
		sc.appendChild( el( 'span', {}, 'Maior' ) );
		sc.style.marginTop = '14px';
		host.appendChild( sc );
	}

	/* ============================================================
	   TABELAS — construção + ordenação por cabeçalho.
	   A visão de tabela é o canal de alívio da paleta: obrigatória.
	   ============================================================ */
	function buildTable( host, cols, rows ) {
		var wrap  = el( 'div', { class: 'mv-an-tablewrap' } );
		var table = el( 'table', { class: 'mv-an-table' } );

		var thead = el( 'thead' );
		var htr   = el( 'tr' );
		cols.forEach( function ( c, i ) {
			var th = el( 'th', {
				scope: 'col',
				class: c.num ? 'is-num' : null,
				'data-sort': c.sortable === false ? null : ( c.num ? 'num' : 'text' ),
				'data-idx': i
			}, c.label );
			htr.appendChild( th );
		} );
		thead.appendChild( htr );
		table.appendChild( thead );

		var tbody = el( 'tbody' );
		rows.forEach( function ( r ) {
			var tr = el( 'tr' );
			cols.forEach( function ( c ) {
				var td = el( 'td', { class: ( c.num ? 'is-num' : '' ) + ( c.muted ? ' is-muted' : '' ) } );
				var v  = c.cell ? c.cell( r ) : r[ c.key ];
				if ( v instanceof Node ) td.appendChild( v );
				else td.textContent = v == null ? '—' : v;
				td.setAttribute( 'data-v', c.sortValue ? c.sortValue( r ) : ( r[ c.key ] != null ? r[ c.key ] : '' ) );
				tr.appendChild( td );
			} );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		host.appendChild( wrap );

		/* Ordenação. */
		htr.addEventListener( 'click', function ( e ) {
			var th = e.target.closest( 'th[data-sort]' );
			if ( ! th ) return;
			var idx  = +th.getAttribute( 'data-idx' );
			var kind = th.getAttribute( 'data-sort' );
			var desc = th.getAttribute( 'aria-sort' ) !== 'descending';

			Array.prototype.forEach.call( htr.children, function ( o ) { o.removeAttribute( 'aria-sort' ); } );
			th.setAttribute( 'aria-sort', desc ? 'descending' : 'ascending' );

			var list = Array.prototype.slice.call( tbody.children );
			list.sort( function ( a, b ) {
				var av = a.children[ idx ].getAttribute( 'data-v' );
				var bv = b.children[ idx ].getAttribute( 'data-v' );
				var d;
				if ( kind === 'num' ) d = ( parseFloat( av ) || 0 ) - ( parseFloat( bv ) || 0 );
				else d = String( av ).localeCompare( String( bv ), 'pt-BR' );
				return desc ? -d : d;
			} );
			list.forEach( function ( tr ) { tbody.appendChild( tr ); } );
		} );

		return table;
	}

	/* Célula com barra de participação embutida. */
	function shareCell( pct ) {
		var box = el( 'span', { class: 'mv-an-swatchcell' } );
		var bar = el( 'span', { class: 'mv-an-minibar' } );
		var fil = el( 'span' );
		fil.style.width = Math.max( 2, pct ).toFixed( 1 ) + '%';
		bar.appendChild( fil );
		box.appendChild( bar );
		box.appendChild( el( 'span', {}, fmtPct( pct ) ) );
		return box;
	}

	/* ── Badge de variação: seta + rótulo, nunca só a cor ────── */
	function deltaBadge( pct ) {
		if ( pct == null ) return el( 'span', { class: 'mv-an-badge mv-an-badge--ghost' }, 'sem base' );
		var up  = pct >= 0;
		var b   = el( 'span', { class: 'mv-an-badge mv-an-badge--' + ( up ? 'up' : 'down' ) } );
		b.appendChild( el( 'span', { class: 'mv-an-badge__ico', 'aria-hidden': 'true' }, up ? '▲' : '▼' ) );
		b.appendChild( document.createTextNode( ( up ? '+' : '−' ) + fmtPct( Math.abs( pct ) ) ) );
		return b;
	}

	/* ── Alternância gráfico / tabela ────────────────────────── */
	function attachToggle( head, chartHost, tableHost ) {
		var box = el( 'div', { class: 'mv-an-toggle' } );
		var bC  = el( 'button', { type: 'button', 'aria-pressed': 'true'  }, 'Gráfico' );
		var bT  = el( 'button', { type: 'button', 'aria-pressed': 'false' }, 'Tabela' );
		tableHost.hidden = true;

		function set( chart ) {
			bC.setAttribute( 'aria-pressed', String( chart ) );
			bT.setAttribute( 'aria-pressed', String( ! chart ) );
			chartHost.hidden = ! chart;
			tableHost.hidden = chart;
		}
		bC.addEventListener( 'click', function () { set( true ); } );
		bT.addEventListener( 'click', function () { set( false ); } );
		box.appendChild( bC );
		box.appendChild( bT );
		/* Entra antes do texto de apoio: o .mv-an-card__hint ocupa a linha
		   inteira (flex-basis 100%), então anexar no fim jogaria o toggle
		   para uma terceira linha em vez de deixá-lo ao lado do título. */
		var hint = head.querySelector( '.mv-an-card__hint' );
		if ( hint ) head.insertBefore( box, hint );
		else head.appendChild( box );
	}

	/* ── Exportação CSV (client-side, no preview) ────────────── */
	function toCsv( rows, cols ) {
		var esc = function ( v ) {
			v = v == null ? '' : String( v );
			return /[";\n]/.test( v ) ? '"' + v.replace( /"/g, '""' ) + '"' : v;
		};
		var out = [ cols.map( function ( c ) { return esc( c.label ); } ).join( ';' ) ];
		rows.forEach( function ( r ) {
			out.push( cols.map( function ( c ) { return esc( c.raw ? c.raw( r ) : r[ c.key ] ); } ).join( ';' ) );
		} );
		/* BOM para o Excel pt-BR abrir com acentuação correta. */
		return '﻿' + out.join( '\r\n' );
	}

	function downloadCsv( name, text ) {
		var blob = new Blob( [ text ], { type: 'text/csv;charset=utf-8;' } );
		var url  = URL.createObjectURL( blob );
		var a    = el( 'a', { href: url, download: name } );
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 );
	}

	/* ── Abas ────────────────────────────────────────────────── */
	function initTabs( root ) {
		var tabs = root.querySelectorAll( '.mv-an-tab' );
		if ( ! tabs.length ) return;

		function select( id ) {
			Array.prototype.forEach.call( tabs, function ( t ) {
				var on = t.getAttribute( 'data-panel' ) === id;
				t.setAttribute( 'aria-selected', String( on ) );
				t.setAttribute( 'tabindex', on ? '0' : '-1' );
			} );
			Array.prototype.forEach.call( root.querySelectorAll( '.mv-an-panel' ), function ( p ) {
				p.hidden = p.id !== id;
			} );
			/* Um painel oculto mede 0 de largura, então o gráfico que
			   estava nele foi desenhado no fallback. Agora que ele tem
			   largura real, redesenha. */
			redrawers.forEach( function ( fn ) { fn(); } );
		}

		Array.prototype.forEach.call( tabs, function ( t, i ) {
			t.addEventListener( 'click', function () { select( t.getAttribute( 'data-panel' ) ); } );
			t.addEventListener( 'keydown', function ( e ) {
				var d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
				if ( ! d ) return;
				e.preventDefault();
				var n = tabs[ ( i + d + tabs.length ) % tabs.length ];
				n.focus();
				select( n.getAttribute( 'data-panel' ) );
			} );
		} );

		select( tabs[ 0 ].getAttribute( 'data-panel' ) );
	}

	/* ============================================================
	   MONTAGEM
	   ============================================================ */
	function build( root, D ) {
		var $ = function ( sel ) { return root.querySelector( sel ); };

		/* ---- KPIs ---- */
		var kpiHost = $( '[data-mv="kpis"]' );
		if ( kpiHost && D.kpis ) {
			D.kpis.forEach( function ( k ) {
				var card = el( 'div', { class: 'mv-an-kpi' } );
				card.appendChild( el( 'span', { class: 'mv-an-kpi__label' }, k.label ) );
				var val = el( 'span', { class: 'mv-an-kpi__value' } );
				val.textContent = k.value;
				if ( k.unit ) val.appendChild( el( 'small', {}, ' ' + k.unit ) );
				card.appendChild( val );
				var foot = el( 'div', { class: 'mv-an-kpi__foot' } );
				foot.appendChild( deltaBadge( k.delta ) );
				foot.appendChild( document.createTextNode( k.note || 'vs. período anterior' ) );
				card.appendChild( foot );
				kpiHost.appendChild( card );
			} );
		}

		/* ---- Receita mensal: novo vs. recorrente ---- */
		var mChart = $( '[data-mv="monthly-chart"]' );
		var mTable = $( '[data-mv="monthly-table"]' );
		if ( mChart && D.monthly ) {
			stackedBars( mChart, D.monthly, { s1Label: 'Recorrentes', s2Label: 'Novos' } );
			buildTable( mTable, [
				{ label: 'Mês', key: 'label' },
				{ label: 'Novos', key: 's2', num: true, cell: function ( r ) { return fmtMoney( r.s2 ); } },
				{ label: 'Recorrentes', key: 's1', num: true, cell: function ( r ) { return fmtMoney( r.s1 ); } },
				{ label: 'Total', key: 'total', num: true,
					sortValue: function ( r ) { return r.s1 + r.s2; },
					cell: function ( r ) { return fmtMoney( r.s1 + r.s2 ); } },
				{ label: '% novos', key: 'pct', num: true,
					sortValue: function ( r ) { return ( r.s1 + r.s2 ) ? r.s2 / ( r.s1 + r.s2 ) : 0; },
					cell: function ( r ) { var t = r.s1 + r.s2; return fmtPct( t ? r.s2 / t * 100 : 0 ); } }
			], D.monthly );
			attachToggle( $( '[data-mv="monthly-toggle"]' ), mChart, mTable );
		}

		/* ---- Comparativo novo vs. recorrente ----
		   Sem ordenação: são cinco indicadores de unidades diferentes
		   (reais, contagem, dias), então reordenar não diria nada. */
		if ( $( '[data-mv="compare"]' ) && D.compare ) {
			buildTable( $( '[data-mv="compare"]' ), [
				{ label: 'Indicador', key: 'metric', sortable: false },
				{ label: 'Cliente novo', key: 'novo', num: true, sortable: false },
				{ label: 'Cliente recorrente', key: 'recorrente', num: true, sortable: false },
				{ label: 'Diferença', key: 'diff', num: true, sortable: false }
			], D.compare );
		}

		/* ---- Coorte ---- */
		if ( $( '[data-mv="cohort"]' ) && D.cohort ) cohort( $( '[data-mv="cohort"]' ), D.cohort );

		/* ---- Segmentos (ordinal: a ordem é o ciclo de vida) ---- */
		if ( $( '[data-mv="segments"]' ) && D.segments ) {
			hBars( $( '[data-mv="segments"]' ), D.segments.map( function ( s ) {
				return { label: s.label, value: s.customers, pct: s.pct };
			} ), {
				ordinal: true,
				format: function ( r ) { return fmtNum( r.value ) + ' · ' + fmtPct( r.pct ); }
			} );
		}

		/* ---- Top produtos ---- */
		var pChart = $( '[data-mv="products-chart"]' );
		var pTable = $( '[data-mv="products-table"]' );
		if ( pChart && D.topProducts ) {
			hBars( pChart, D.topProducts.map( function ( p ) {
				return { label: p.name, value: p.revenue };
			} ) );
			buildTable( pTable, [
				{ label: 'Produto', key: 'name' },
				{ label: 'Receita', key: 'revenue', num: true, cell: function ( r ) { return fmtMoney( r.revenue ); } },
				{ label: 'Unidades', key: 'qty', num: true, cell: function ( r ) { return fmtNum( r.qty ); } },
				{ label: 'Ticket médio', key: 'avg', num: true,
					sortValue: function ( r ) { return r.qty ? r.revenue / r.qty : 0; },
					cell: function ( r ) { return fmtMoney2( r.qty ? r.revenue / r.qty : 0 ); } },
				{ label: 'Participação', key: 'share', num: true, cell: function ( r ) { return shareCell( r.share ); } }
			], D.topProducts );
			attachToggle( $( '[data-mv="products-toggle"]' ), pChart, pTable );
		}

		/* ---- Categorias ---- */
		if ( $( '[data-mv="categories"]' ) && D.categories ) {
			hBars( $( '[data-mv="categories"]' ), D.categories.map( function ( c ) {
				return { label: c.name, value: c.revenue };
			} ) );
		}

		/* ---- Curva ABC ---- Classe é uma faixa ordenada, não um
		   nome solto: por isso a rampa de uma matiz, e não cores soltas. */
		if ( $( '[data-mv="abc"]' ) && D.abc ) {
			hBars( $( '[data-mv="abc"]' ), D.abc.map( function ( a ) {
				return { label: a.label, value: a.revenue, products: a.products, pct: a.pct };
			} ), {
				ordinal: 'desc',
				format: function ( r ) { return fmtNum( r.products ) + ' produtos · ' + fmtPct( r.pct ); }
			} );
		}

		/* ---- Entrada vs. recompra ---- */
		if ( $( '[data-mv="entry"]' ) && D.entry ) {
			buildTable( $( '[data-mv="entry"]' ), [
				{ label: 'Produto', key: 'name' },
				{ label: '1ª compra', key: 'first', num: true, cell: function ( r ) { return fmtPct( r.first ); } },
				{ label: 'Recompra', key: 'repeat', num: true, cell: function ( r ) { return fmtPct( r.repeat ); } },
				{ label: 'Papel', key: 'role', cell: function ( r ) {
					var b = el( 'span', { class: 'mv-an-badge mv-an-badge--' + ( r.role === 'Entrada' ? 'accent' : 'ghost' ) }, r.role );
					return b;
				} }
			], D.entry );
		}

		/* ---- Co-compra ---- */
		if ( $( '[data-mv="basket"]' ) && D.basket ) {
			buildTable( $( '[data-mv="basket"]' ), [
				{ label: 'Produto A', key: 'a' },
				{ label: 'Produto B', key: 'b' },
				{ label: 'Pedidos juntos', key: 'orders', num: true, cell: function ( r ) { return fmtNum( r.orders ); } },
				{ label: 'Suporte', key: 'support', num: true, cell: function ( r ) { return fmtPct( r.support, 2 ); } },
				{ label: 'Confiança', key: 'confidence', num: true, cell: function ( r ) { return fmtPct( r.confidence ); } },
				{ label: 'Lift', key: 'lift', num: true, cell: function ( r ) {
					var s = el( 'span', { class: 'mv-an-swatchcell' } );
					s.appendChild( el( 'strong', {}, r.lift.toFixed( 2 ).replace( '.', ',' ) ) );
					if ( r.lift >= 2 ) s.appendChild( el( 'span', { class: 'mv-an-badge mv-an-badge--accent' }, 'forte' ) );
					return s;
				} }
			], D.basket );
		}

		/* ---- Lacuna de categoria ---- */
		if ( $( '[data-mv="gaps"]' ) && D.gaps ) {
			var gapHost = $( '[data-mv="gaps"]' );
			var list    = el( 'div', { class: 'mv-an-oppo' } );
			D.gaps.forEach( function ( g ) {
				var it = el( 'div', { class: 'mv-an-oppo__item' } );
				var to = el( 'div', { class: 'mv-an-oppo__to' } );
				to.appendChild( document.createTextNode( 'Compra ' ) );
				to.appendChild( el( 'em', {}, g.from ) );
				to.appendChild( document.createTextNode( ', nunca comprou ' ) );
				to.appendChild( el( 'em', {}, g.to ) );
				it.appendChild( to );
				it.appendChild( el( 'span', { class: 'mv-an-badge mv-an-badge--accent' }, fmtNum( g.customers ) + ' clientes' ) );
				it.appendChild( el( 'div', { class: 'mv-an-oppo__from' },
					'Sugestão: ' + g.suggestion + ' · receita potencial ' + fmtMoney( g.potential ) ) );
				list.appendChild( it );
			} );
			gapHost.appendChild( list );
		}

		/* ---- Lista acionável + CSV ---- */
		if ( $( '[data-mv="oppo"]' ) && D.opportunities ) {
			var cols = [
				{ label: 'Cliente', key: 'customer' },
				{ label: 'E-mail', key: 'email', muted: true },
				{ label: 'Já comprou', key: 'bought' },
				{ label: 'Sugerir', key: 'suggest' },
				{ label: 'Motivo', key: 'reason', muted: true },
				{ label: 'Potencial', key: 'potential', num: true, cell: function ( r ) { return fmtMoney2( r.potential ); } }
			];
			buildTable( $( '[data-mv="oppo"]' ), cols, D.opportunities );

			var btn = $( '[data-mv="oppo-csv"]' );
			if ( btn ) btn.addEventListener( 'click', function () {
				downloadCsv( 'multivegetal-oportunidades.csv', toCsv( D.opportunities, cols ) );
			} );
		}

		/* ---- Meta ---- */
		var meta = $( '[data-mv="meta"]' );
		if ( meta && D.meta ) {
			meta.innerHTML = '';
			[
				[ 'Período', D.meta.period ],
				[ 'Comparado com', D.meta.previous ],
				[ 'Pedidos considerados', D.meta.statuses ],
				[ 'Atualizado', D.meta.updated ]
			].forEach( function ( p ) {
				var s = el( 'span' );
				s.appendChild( document.createTextNode( p[ 0 ] + ': ' ) );
				s.appendChild( el( 'strong', {}, p[ 1 ] ) );
				meta.appendChild( s );
			} );
		}
	}

	/* ── Controles do admin ──────────────────────────────────────
	   Só existem quando o plugin roda dentro do WordPress; no
	   preview.html não há MV_AN_CFG e nada disso é ligado. */
	function initAdminControls( root ) {
		var form = root.querySelector( '[data-mv="filters"]' );
		if ( form ) {
			var periodSel = form.querySelector( '[data-mv="period"]' );
			var customBox = form.querySelector( '[data-mv="custom-range"]' );

			/* "Personalizado" revela os dois campos de data e espera o
			   Aplicar. Recarregar a página no momento em que o usuário
			   escolhe a opção o mandaria de volta antes de informar as
			   datas — os demais períodos, esses sim, aplicam na hora. */
			Array.prototype.forEach.call( form.querySelectorAll( 'select' ), function ( s ) {
				s.addEventListener( 'change', function () {
					if ( s === periodSel && s.value === 'custom' ) {
						if ( customBox ) customBox.hidden = false;
						var first = form.querySelector( 'input[name="mv_after"]' );
						if ( first ) first.focus();
						return;
					}
					if ( customBox && s === periodSel ) customBox.hidden = true;
					form.submit();
				} );
			} );
		}

		var refresh = root.querySelector( '[data-mv="refresh"]' );
		if ( ! refresh || ! window.MV_AN_CFG ) return;

		refresh.addEventListener( 'click', function () {
			if ( refresh.disabled ) return;
			var label = refresh.textContent;
			refresh.disabled = true;
			refresh.textContent = 'Recalculando…';

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', MV_AN_CFG.ajaxUrl, true );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
			xhr.onload = function () { window.location.reload(); };
			xhr.onerror = function () {
				refresh.disabled = false;
				refresh.textContent = label;
			};
			xhr.send( 'action=mv_an_refresh&nonce=' + encodeURIComponent( MV_AN_CFG.nonce ) );
		} );
	}

	/* ── Boot ────────────────────────────────────────────────── */
	function init() {
		var root = document.querySelector( '.mv-an' );
		if ( ! root || root.dataset.mvanInit === '1' ) return;
		root.dataset.mvanInit = '1';

		initTabs( root );
		initAdminControls( root );

		/* build() já ignora cada bloco cujo dado não veio, então roda
		   mesmo quando só há "meta" (tabelas de lookup ainda vazias). */
		var D = window.MV_AN_DATA;
		if ( D ) build( root, D );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	/* Exposto para o preview e para testes manuais. */
	window.MVAN = { stackedBars: stackedBars, hBars: hBars, cohort: cohort, buildTable: buildTable };
} )();
