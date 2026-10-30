/* ============================================================
   MULTIVEGETAL — Analytics Dashboard
   Sem dependências externas, mesma regra dos demais widgets
   (ver assets/grid.js). Os gráficos são SVG gerado aqui.

   Fontes dos dados:
   - window.MV_AN_DATA — snapshot em cache, injetado pelo PHP (ou
     pelo <script> do preview.html quando roda fora do WP);
   - AJAX sob demanda — explorador de clientes, de pedidos e a ficha
     do cliente. No preview, window.MV_AN_MOCK responde no lugar.
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
	function fmtDec( v, d ) { return ( v || 0 ).toFixed( d == null ? 1 : d ).replace( '.', ',' ); }

	/* Abrevia no eixo para não colidir rótulo: 12,4 mil / 1,2 mi */
	function fmtAxis( v ) {
		var a = Math.abs( v );
		if ( a >= 1e6 ) return ( v / 1e6 ).toFixed( 1 ).replace( '.', ',' ) + ' mi';
		if ( a >= 1e3 ) return ( v / 1e3 ).toFixed( a >= 1e4 ? 0 : 1 ).replace( '.', ',' ) + ' mil';
		return num.format( Math.round( v * 100 ) / 100 );
	}

	function plural( n, one, many ) { return fmtNum( n ) + ' ' + ( n === 1 ? one : many ); }

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

	function empty( host, msg ) {
		host.appendChild( el( 'div', { class: 'mv-an-empty' }, msg || 'Sem dados no período selecionado.' ) );
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
	function axisScale( maxValue, integer ) {
		if ( maxValue <= 0 ) return { max: 1, step: 1, count: 1 };
		var step = niceStep( maxValue / 4 );
		/* Contagem (pedidos, clientes) não tem meio pedido no eixo. */
		if ( integer ) step = Math.max( 1, Math.ceil( step ) );
		var max  = Math.ceil( maxValue / step ) * step;
		return { max: max, step: step, count: Math.round( max / step ) };
	}

	/* Rótulos do eixo X: de quantos em quantos cabem sem colidir.
	   A largura mínima sai do rótulo mais longo ("sem. 07/09" pede mais
	   que "set/26"), não de um número fixo. */
	function labelEvery( rows, slot ) {
		var longest = 0;
		rows.forEach( function ( r ) { longest = Math.max( longest, String( r.label ).length ); } );
		var need = Math.max( 34, longest * 6.4 + 12 );
		return Math.max( 1, Math.ceil( need / slot ) );
	}

	/* Gráficos que precisam ser redesenhados quando a largura muda
	   (troca de aba ou resize da janela). Um SVG com viewBox fixo
	   encolheria o texto junto com o desenho: a 390px os rótulos dos
	   meses caíam para ~4px. Redesenhar na largura real mantém a
	   tipografia no tamanho declarado. */
	var redrawers = [];

	function redrawAll() {
		/* Gráfico que saiu do DOM (ficha de cliente fechada) sai da lista. */
		redrawers = redrawers.filter( function ( r ) { return document.body.contains( r.host ); } );
		redrawers.forEach( function ( r ) { r.fn(); } );
	}

	( function () {
		var t;
		window.addEventListener( 'resize', function () {
			clearTimeout( t );
			t = setTimeout( redrawAll, 150 );
		} );
	} )();

	/* ── Tooltip compartilhado ───────────────────────────────── */

	function tipRows( tip, title, rows ) {
		tip.innerHTML = '';
		tip.appendChild( el( 'div', { class: 'mv-an-tip__title' }, title ) );
		rows.forEach( function ( row ) {
			var line = el( 'div', { class: 'mv-an-tip__row' } );
			var key  = el( 'span', { class: 'mv-an-tip__key' } );
			if ( row.color ) key.appendChild( el( 'i', { class: 'mv-an-tip__dot', style: 'background:' + row.color } ) );
			key.appendChild( document.createTextNode( row.key ) );
			line.appendChild( key );
			line.appendChild( el( 'span', { class: 'mv-an-tip__val' }, row.val ) );
			tip.appendChild( line );
		} );
	}

	function placeTip( tip, host, xPx, evt ) {
		var box = host.getBoundingClientRect();
		/* Perto das bordas o tooltip encosta para dentro em vez de vazar. */
		var half = ( tip.offsetWidth || 160 ) / 2;
		var x    = Math.min( Math.max( xPx, half ), box.width - half );
		tip.style.left = x + 'px';
		tip.style.top  = Math.max( 0, ( evt.clientY - box.top ) - 16 ) + 'px';
		tip.classList.add( 'is-on' );
	}

	/* ============================================================
	   GRÁFICO 1 — Barras verticais (empilhadas ou série única).

	   opts.series = [ { key, label, cls, color } ] de baixo para cima.
	   Duas séries => legenda obrigatória (no markup do card).
	   ============================================================ */
	function barChart( host, rows, opts ) {
		opts = opts || {};
		if ( ! rows || ! rows.length ) return empty( host );

		var tip = el( 'div', { class: 'mv-an-tip' } );

		function draw() {
			var prev = host.querySelector( 'svg' );
			if ( prev ) host.removeChild( prev );
			drawBars( host, rows, opts, tip );
		}

		draw();
		host.appendChild( tip );
		redrawers.push( { host: host, fn: draw } );
	}

	/* Compatibilidade: o contrato antigo (s1 recorrente, s2 novo). */
	function stackedBars( host, rows, opts ) {
		opts = opts || {};
		barChart( host, rows, {
			alt: opts.alt || 'Receita por período, separada entre clientes novos e recorrentes.',
			series: [
				{ key: 's1', label: opts.s1Label || 'Recorrentes', cls: 's1', color: 'var(--mv-s1)' },
				{ key: 's2', label: opts.s2Label || 'Novos',       cls: 's2', color: 'var(--mv-s2)' }
			],
			tipExtra: function ( r ) {
				var t = ( r.s1 || 0 ) + ( r.s2 || 0 );
				return [ { key: '% de novos', val: fmtPct( t ? ( r.s2 || 0 ) / t * 100 : 0 ) } ];
			}
		} );
	}

	function drawBars( host, rows, opts, tip ) {
		/* Painel oculto tem clientWidth 0: cai no padrão e é redesenhado
		   quando a aba abre (initTabs dispara os redrawers). */
		var W = Math.max( 300, Math.round( host.clientWidth || 900 ) );
		var H = opts.height || ( W < 560 ? 220 : 300 );
		var pad = { t: 16, r: 12, b: 34, l: W < 560 ? 46 : 60 };
		var iw = W - pad.l - pad.r;
		var ih = H - pad.t - pad.b;
		var series = opts.series || [ { key: 'value', label: 'Valor', cls: 's1', color: 'var(--mv-s1)' } ];
		var fmt    = opts.format || fmtMoney;

		var totals = rows.map( function ( r ) {
			return series.reduce( function ( a, s ) { return a + ( r[ s.key ] || 0 ); }, 0 );
		} );
		var scale = axisScale( Math.max.apply( null, totals ), opts.integer );
		var max   = scale.max;

		var svg = svgEl( 'svg', {
			viewBox: '0 0 ' + W + ' ' + H, width: W, height: H,
			role: 'img', 'aria-label': opts.alt || 'Gráfico de barras.'
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

		var slot  = iw / rows.length;
		var bw    = Math.max( 2, Math.min( opts.maxBar || 46, slot * 0.62 ) );
		var gBars = svgEl( 'g', { class: 'mv-an-stack' } );
		var gHits = svgEl( 'g' );

		/* A contagem dos rótulos parte do ÚLTIMO balde para trás: assim o
		   mais recente — o que o leitor procura primeiro — sempre aparece. */
		var everyN = labelEvery( rows, slot );
		var last   = rows.length - 1;

		rows.forEach( function ( r, idx ) {
			var cx   = pad.l + slot * idx + slot / 2;
			var x    = cx - bw / 2;
			var base = pad.t + ih;

			/* Índice do segmento de cima com valor — só ele leva a ponta
			   arredondada; os de baixo encostam em outro segmento. */
			var topIdx = -1;
			series.forEach( function ( s, si ) { if ( ( r[ s.key ] || 0 ) > 0 ) topIdx = si; } );

			series.forEach( function ( s, si ) {
				var val = r[ s.key ] || 0;
				var h   = ( val / max ) * ih;
				if ( h <= 0 ) return;
				var yTop = base - h;
				if ( si === topIdx ) {
					gBars.appendChild( svgEl( 'path', {
						d: topRoundedPath( x, yTop, bw, h, 4 ),
						class: 'mv-an-mark mv-an-mark--' + s.cls, 'data-col': idx
					} ) );
				} else {
					gBars.appendChild( svgEl( 'rect', {
						x: x, y: yTop, width: bw, height: h,
						class: 'mv-an-mark mv-an-mark--' + s.cls, 'data-col': idx
					} ) );
				}
				base = yTop;
			} );

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

		var gBase = svgEl( 'g', { class: 'mv-an-chart__base' } );
		gBase.appendChild( svgEl( 'line', { x1: pad.l, x2: pad.l + iw, y1: pad.t + ih, y2: pad.t + ih } ) );
		svg.appendChild( gBase );
		svg.appendChild( gHits );

		host.insertBefore( svg, host.firstChild );

		function show( idx, evt ) {
			var r = rows[ idx ];
			var lines = series.slice().reverse().map( function ( s ) {
				return { key: s.label, val: fmt( r[ s.key ] || 0 ), color: series.length > 1 ? s.color : null };
			} );
			if ( series.length > 1 ) lines.push( { key: 'Total', val: fmt( totals[ idx ] ) } );
			if ( opts.tipExtra ) lines = lines.concat( opts.tipExtra( r ) );
			tipRows( tip, opts.tipTitle ? opts.tipTitle( r ) : r.label, lines );

			var box = host.getBoundingClientRect();
			placeTip( tip, host, ( pad.l + slot * idx + slot / 2 ) / W * box.width, evt );

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
			var c = e.target.getAttribute && e.target.getAttribute( 'data-col' );
			if ( c != null ) show( +c, e );
		} );
		gHits.addEventListener( 'mouseleave', hide );
	}

	/* ============================================================
	   GRÁFICO 2 — Linha: período atual vs. período comparado.

	   Mesma unidade nas duas séries, então um eixo só. O período
	   comparado é contexto, não protagonista: linha cinza e mais
	   fina (forma "ênfase"), a atual na cor da marca com área suave.
	   ============================================================ */
	function lineChart( host, rows, opts ) {
		opts = opts || {};
		if ( ! rows || ! rows.length ) return empty( host );

		var tip   = el( 'div', { class: 'mv-an-tip' } );
		var state = { metric: opts.metric };

		function draw() {
			var prev = host.querySelector( 'svg' );
			if ( prev ) host.removeChild( prev );
			drawLine( host, rows, state.metric, tip );
		}

		draw();
		host.appendChild( tip );
		redrawers.push( { host: host, fn: draw } );

		return {
			setMetric: function ( m ) { state.metric = m; draw(); }
		};
	}

	function drawLine( host, rows, metric, tip ) {
		var W = Math.max( 300, Math.round( host.clientWidth || 900 ) );
		var H = W < 560 ? 220 : 300;
		var pad = { t: 18, r: 18, b: 34, l: W < 560 ? 46 : 60 };
		var iw = W - pad.l - pad.r;
		var ih = H - pad.t - pad.b;

		var cur  = rows.map( function ( r ) { return metric.get( r ); } );
		var prev = rows.map( function ( r ) { return metric.getPrev( r ); } );
		var scale = axisScale( Math.max.apply( null, cur.concat( prev ) ), metric.integer );
		var max   = scale.max;

		var svg = svgEl( 'svg', {
			viewBox: '0 0 ' + W + ' ' + H, width: W, height: H,
			role: 'img', 'aria-label': metric.label + ' no período atual comparado ao período anterior.'
		} );

		var n    = rows.length;
		var step = n > 1 ? iw / ( n - 1 ) : 0;
		var x = function ( i ) { return n > 1 ? pad.l + step * i : pad.l + iw / 2; };
		var y = function ( v ) { return pad.t + ih - ( v / max ) * ih; };

		var gGrid = svgEl( 'g', { class: 'mv-an-chart__grid' } );
		for ( var i = 0; i <= scale.count; i++ ) {
			var v = scale.step * i;
			gGrid.appendChild( svgEl( 'line', { x1: pad.l, x2: pad.l + iw, y1: y( v ), y2: y( v ) } ) );
			var t = svgEl( 'text', { x: pad.l - 10, y: y( v ) + 4, 'text-anchor': 'end', class: 'mv-an-chart__tick' } );
			t.textContent = fmtAxis( v );
			gGrid.appendChild( t );
		}
		svg.appendChild( gGrid );

		var everyN = labelEvery( rows, n > 1 ? step : iw );
		rows.forEach( function ( r, idx ) {
			if ( ( n - 1 - idx ) % everyN !== 0 ) return;
			var lb = svgEl( 'text', { x: x( idx ), y: H - 12, 'text-anchor': 'middle', class: 'mv-an-chart__tick' } );
			lb.textContent = r.label;
			svg.appendChild( lb );
		} );

		var path = function ( vals ) {
			return vals.map( function ( v, i ) { return ( i ? 'L' : 'M' ) + x( i ).toFixed( 1 ) + ',' + y( v ).toFixed( 1 ); } ).join( '' );
		};

		var gBase = svgEl( 'g', { class: 'mv-an-chart__base' } );
		gBase.appendChild( svgEl( 'line', { x1: pad.l, x2: pad.l + iw, y1: pad.t + ih, y2: pad.t + ih } ) );
		svg.appendChild( gBase );

		if ( n > 1 ) {
			svg.appendChild( svgEl( 'path', {
				d: path( cur ) + 'L' + x( n - 1 ) + ',' + ( pad.t + ih ) + 'L' + x( 0 ) + ',' + ( pad.t + ih ) + 'Z',
				class: 'mv-an-chart__area'
			} ) );
			svg.appendChild( svgEl( 'path', { d: path( prev ), class: 'mv-an-chart__line mv-an-chart__line--prev' } ) );
			svg.appendChild( svgEl( 'path', { d: path( cur ), class: 'mv-an-chart__line mv-an-chart__line--cur' } ) );
		}

		/* Marcador só no último ponto: é o "onde estamos", e rotular
		   todos os pontos viraria ruído. */
		svg.appendChild( svgEl( 'circle', { cx: x( n - 1 ), cy: y( cur[ n - 1 ] ), r: 4.5, class: 'mv-an-chart__dot' } ) );

		var cross = svgEl( 'line', { x1: 0, x2: 0, y1: pad.t, y2: pad.t + ih, class: 'mv-an-chart__crosshair', visibility: 'hidden' } );
		var dCur  = svgEl( 'circle', { r: 4.5, class: 'mv-an-chart__dot', visibility: 'hidden' } );
		var dPrev = svgEl( 'circle', { r: 4, class: 'mv-an-chart__dot mv-an-chart__dot--prev', visibility: 'hidden' } );
		svg.appendChild( cross );
		svg.appendChild( dPrev );
		svg.appendChild( dCur );

		var hit = svgEl( 'rect', { x: pad.l - 8, y: pad.t, width: iw + 16, height: ih, class: 'mv-an-chart__hit' } );
		svg.appendChild( hit );

		host.insertBefore( svg, host.firstChild );

		function show( evt ) {
			var box = svg.getBoundingClientRect();
			var px  = ( evt.clientX - box.left ) / box.width * W;
			var idx = n > 1 ? Math.round( ( px - pad.l ) / step ) : 0;
			idx = Math.max( 0, Math.min( n - 1, idx ) );
			var r = rows[ idx ];

			cross.setAttribute( 'x1', x( idx ) );
			cross.setAttribute( 'x2', x( idx ) );
			dCur.setAttribute( 'cx', x( idx ) );  dCur.setAttribute( 'cy', y( cur[ idx ] ) );
			dPrev.setAttribute( 'cx', x( idx ) ); dPrev.setAttribute( 'cy', y( prev[ idx ] ) );
			[ cross, dCur, dPrev ].forEach( function ( m ) { m.setAttribute( 'visibility', 'visible' ); } );

			var d = prev[ idx ] > 0 ? ( cur[ idx ] - prev[ idx ] ) / prev[ idx ] * 100 : null;
			tipRows( tip, r.label, [
				{ key: 'Atual', val: metric.format( cur[ idx ] ), color: 'var(--mv-s1)' },
				{ key: r.prevLabel ? 'Comparado (' + r.prevLabel + ')' : 'Comparado', val: metric.format( prev[ idx ] ), color: 'var(--mv-prev)' },
				{ key: 'Variação', val: d == null ? '—' : ( d >= 0 ? '+' : '−' ) + fmtPct( Math.abs( d ) ) }
			] );
			placeTip( tip, host, x( idx ) / W * box.width, evt );
		}

		function hide() {
			tip.classList.remove( 'is-on' );
			[ cross, dCur, dPrev ].forEach( function ( m ) { m.setAttribute( 'visibility', 'hidden' ); } );
		}

		hit.addEventListener( 'mousemove', show );
		hit.addEventListener( 'mouseleave', hide );
	}

	/* ── Sparkline do KPI — tendência sem eixo, sem texto ────── */
	function sparkline( values ) {
		var W = 120, H = 28, n = values.length;
		var max = Math.max.apply( null, values ), min = Math.min.apply( null, values );
		var span = max - min || 1;
		var pts = values.map( function ( v, i ) {
			return [ ( i / ( n - 1 ) ) * W, H - 3 - ( ( v - min ) / span ) * ( H - 6 ) ];
		} );
		var d = pts.map( function ( p, i ) { return ( i ? 'L' : 'M' ) + p[ 0 ].toFixed( 1 ) + ',' + p[ 1 ].toFixed( 1 ); } ).join( '' );
		var svg = svgEl( 'svg', { viewBox: '0 0 ' + W + ' ' + H, preserveAspectRatio: 'none', class: 'mv-an-spark', 'aria-hidden': 'true' } );
		svg.appendChild( svgEl( 'path', { d: d + 'L' + W + ',' + H + 'L0,' + H + 'Z', class: 'mv-an-spark__area' } ) );
		svg.appendChild( svgEl( 'path', { d: d, class: 'mv-an-spark__line' } ) );
		return svg;
	}

	/* ============================================================
	   Barras horizontais de série única.
	   Nominal (produto, categoria): todas no mesmo tom.
	   Ordinal (segmento de ciclo de vida): rampa de uma matiz.
	   ============================================================ */
	function hBars( host, rows, opts ) {
		opts = opts || {};
		if ( ! rows || ! rows.length ) return empty( host );
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
	function scaleLegend( host, lo, hi ) {
		var sc = el( 'div', { class: 'mv-an-scale' } );
		sc.appendChild( el( 'span', {}, lo ) );
		var steps = el( 'span', { class: 'mv-an-scale__steps' } );
		[ 1, 2, 3, 4, 5 ].forEach( function ( n ) {
			steps.appendChild( el( 'i', { style: 'background:var(--mv-r' + n + ')' } ) );
		} );
		sc.appendChild( steps );
		sc.appendChild( el( 'span', {}, hi ) );
		sc.style.marginTop = '14px';
		host.appendChild( sc );
	}

	function cohort( host, rows ) {
		if ( ! rows || ! rows.length ) return empty( host, 'Sem coortes suficientes no período.' );
		var wrap  = el( 'div', { class: 'mv-an-tablewrap' } );
		var table = el( 'table', { class: 'mv-an-cohort' } );

		var thead = el( 'thead' );
		var htr   = el( 'tr' );
		[ 'Mês da 1ª compra', 'Clientes', '30 dias', '60 dias', '90 dias' ].forEach( function ( h ) {
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

		scaleLegend( host, 'Menor recompra', 'Maior' );
	}

	/* ============================================================
	   MAPA DE CALOR — dia da semana × hora.
	   Rampa de uma matiz relativa ao pico do período: o que importa
	   é onde a loja vende MAIS, não o número absoluto.
	   ============================================================ */
	function weekHeatmap( host, rows ) {
		if ( ! rows || ! rows.length ) return empty( host );

		var max = 0, peak = null, dayTot = [];
		rows.forEach( function ( r, d ) {
			var tot = 0;
			r.cells.forEach( function ( n, h ) {
				tot += n;
				if ( n > max ) { max = n; peak = { d: r.label, h: h, n: n }; }
			} );
			dayTot.push( { label: r.label, n: tot } );
		} );
		if ( ! max ) return empty( host );

		var tone = function ( n ) {
			if ( ! n ) return 'r0';
			var q = n / max;
			return q <= 0.2 ? 'r1' : q <= 0.4 ? 'r2' : q <= 0.6 ? 'r3' : q <= 0.8 ? 'r4' : 'r5';
		};

		var bestDay = dayTot.slice().sort( function ( a, b ) { return b.n - a.n; } )[ 0 ];
		var sum = el( 'p', { class: 'mv-an-insight' } );
		sum.appendChild( document.createTextNode( 'Pico: ' ) );
		sum.appendChild( el( 'strong', {}, peak.d + ' às ' + peak.h + 'h' ) );
		sum.appendChild( document.createTextNode( ' (' + plural( peak.n, 'pedido', 'pedidos' ) + '). Dia mais forte: ' ) );
		sum.appendChild( el( 'strong', {}, bestDay.label ) );
		sum.appendChild( document.createTextNode( ' (' + plural( bestDay.n, 'pedido', 'pedidos' ) + ').' ) );
		host.appendChild( sum );

		var wrap  = el( 'div', { class: 'mv-an-tablewrap' } );
		var table = el( 'table', { class: 'mv-an-heat' } );
		var thead = el( 'thead' );
		var htr   = el( 'tr' );
		htr.appendChild( el( 'th', { scope: 'col' }, '' ) );
		for ( var h = 0; h < 24; h++ ) {
			htr.appendChild( el( 'th', { scope: 'col' }, h % 3 === 0 ? h + 'h' : '' ) );
		}
		htr.appendChild( el( 'th', { scope: 'col', class: 'is-total' }, 'Total' ) );
		thead.appendChild( htr );
		table.appendChild( thead );

		var tbody = el( 'tbody' );
		rows.forEach( function ( r, d ) {
			var tr = el( 'tr' );
			tr.appendChild( el( 'th', { scope: 'row' }, r.label ) );
			r.cells.forEach( function ( n, hh ) {
				var label = r.label + ' ' + hh + 'h: ' + plural( n, 'pedido', 'pedidos' ) +
					( r.revenue && r.revenue[ hh ] ? ' · ' + fmtMoney( r.revenue[ hh ] ) : '' );
				tr.appendChild( el( 'td', { class: tone( n ), title: label, 'aria-label': label } ) );
			} );
			tr.appendChild( el( 'td', { class: 'is-total' }, fmtNum( dayTot[ d ].n ) ) );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		host.appendChild( wrap );

		scaleLegend( host, 'Menos pedidos', 'Mais' );
	}

	/* ============================================================
	   TABELAS — construção + ordenação por cabeçalho.
	   A visão de tabela é o canal de alívio da paleta: obrigatória.
	   ============================================================ */
	function buildTable( host, cols, rows ) {
		if ( ! rows || ! rows.length ) return empty( host );
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

	/* Duas linhas na célula: valor principal + apoio em tom suave. */
	function stackCell( main, sub ) {
		var box = el( 'span', { class: 'mv-an-stackcell' } );
		if ( main instanceof Node ) box.appendChild( main ); else box.appendChild( el( 'span', {}, main ) );
		if ( sub ) box.appendChild( el( 'span', { class: 'mv-an-sub' }, sub ) );
		return box;
	}

	/* Nome de cliente que abre a ficha. A delegação no root trata o clique. */
	function custLink( id, name ) {
		if ( ! id ) return el( 'span', {}, name );
		return el( 'button', { type: 'button', class: 'mv-an-link', 'data-customer': id }, name );
	}

	/* ── Badges ───────────────────────────────────────────────── */

	/* Variação: seta + rótulo, nunca só a cor. "invert" para custo
	   (desconto, reembolso): subir é ruim, então a cor troca, a seta não. */
	function deltaBadge( pct, invert ) {
		if ( pct == null ) return el( 'span', { class: 'mv-an-badge mv-an-badge--ghost' }, 'sem base' );
		var up   = pct >= 0;
		var good = invert ? ! up : up;
		var b    = el( 'span', { class: 'mv-an-badge mv-an-badge--' + ( good ? 'up' : 'down' ) } );
		b.appendChild( el( 'span', { class: 'mv-an-badge__ico', 'aria-hidden': 'true' }, up ? '▲' : '▼' ) );
		b.appendChild( document.createTextNode( ( up ? '+' : '−' ) + fmtPct( Math.abs( pct ) ) ) );
		return b;
	}

	/* Status do WooCommerce: o rótulo vem do próprio WC; a cor só reforça. */
	var STATUS_TONE = {
		'wc-completed': 'up', 'wc-processing': 'accent', 'wc-on-hold': 'ghost',
		'wc-pending': 'ghost', 'wc-refunded': 'down', 'wc-cancelled': 'down', 'wc-failed': 'down'
	};
	function statusBadge( status, label ) {
		return el( 'span', { class: 'mv-an-badge mv-an-badge--' + ( STATUS_TONE[ status ] || 'ghost' ) }, label || status );
	}

	/* Estágio de relacionamento: rampa ordinal, como no quadro de estágios. */
	var SEG_TONE = { novo: 'r1', rec: 'r2', fiel: 'r5', risco: 'warn', inativo: 'ghost' };
	function segBadge( seg, label ) {
		return el( 'span', { class: 'mv-an-badge mv-an-badge--seg-' + ( SEG_TONE[ seg ] || 'ghost' ) }, label );
	}

	function typeBadge( returning ) {
		return el( 'span', { class: 'mv-an-badge mv-an-badge--' + ( returning ? 'ghost' : 'accent' ) }, returning ? 'Recorrente' : 'Novo' );
	}

	/* ── Alternância gráfico / tabela ────────────────────────── */
	function attachToggle( head, chartHost, tableHost ) {
		if ( ! head ) return;
		var box = el( 'div', { class: 'mv-an-toggle' } );
		var bC  = el( 'button', { type: 'button', 'aria-pressed': 'true'  }, 'Gráfico' );
		var bT  = el( 'button', { type: 'button', 'aria-pressed': 'false' }, 'Tabela' );
		tableHost.hidden = true;

		function set( chart ) {
			bC.setAttribute( 'aria-pressed', String( chart ) );
			bT.setAttribute( 'aria-pressed', String( ! chart ) );
			chartHost.hidden = ! chart;
			tableHost.hidden = chart;
			if ( chart ) redrawAll();
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
			/* Mesma defesa do servidor contra fórmula no Excel. */
			if ( /^[=+\-@\t\r]/.test( v ) && isNaN( +v.replace( ',', '.' ) ) ) v = "'" + v;
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

	/* ── AJAX ─────────────────────────────────────────────────
	   GET com os filtros da tela + os do explorador. No preview não
	   há MV_AN_CFG: o MV_AN_MOCK responde com dados fictícios. */
	function api( action, params, cb ) {
		var CFG = window.MV_AN_CFG;
		if ( ! CFG ) {
			if ( typeof window.MV_AN_MOCK !== 'function' ) return cb( new Error( 'Indisponível fora do WordPress.' ) );
			setTimeout( function () {
				var r;
				try { r = window.MV_AN_MOCK( action, params ); } catch ( e ) { return cb( e ); }
				r ? cb( null, r ) : cb( new Error( 'Não encontrado.' ) );
			}, 160 );
			return;
		}

		var q = [ 'action=' + encodeURIComponent( action ), 'nonce=' + encodeURIComponent( CFG.nonce ) ];
		var all = {}, k;
		for ( k in CFG.args ) all[ k ] = CFG.args[ k ];
		for ( k in params ) all[ k ] = params[ k ];
		for ( k in all ) {
			if ( all[ k ] === '' || all[ k ] == null ) continue;
			q.push( encodeURIComponent( k ) + '=' + encodeURIComponent( all[ k ] ) );
		}

		var xhr = new XMLHttpRequest();
		xhr.open( 'GET', CFG.ajaxUrl + '?' + q.join( '&' ), true );
		xhr.onload = function () {
			var res;
			try { res = JSON.parse( xhr.responseText ); } catch ( e ) { return cb( new Error( 'Resposta inválida do servidor.' ) ); }
			if ( res && res.success ) cb( null, res.data );
			else cb( new Error( ( res && res.data && res.data.message ) || 'Falha ao consultar.' ) );
		};
		xhr.onerror = function () { cb( new Error( 'Falha de conexão.' ) ); };
		xhr.send();
	}

	function exportHref( type, params ) {
		var CFG = window.MV_AN_CFG;
		if ( ! CFG || ! CFG.exportUrl ) return null;
		var q = [ 'action=mv_an_export', 'type=' + type, '_wpnonce=' + encodeURIComponent( CFG.exportNonce ) ];
		var all = {}, k;
		for ( k in CFG.args ) all[ k ] = CFG.args[ k ];
		for ( k in params ) if ( k !== 'page' && k !== 'per' ) all[ k ] = params[ k ];
		for ( k in all ) {
			if ( all[ k ] === '' || all[ k ] == null ) continue;
			q.push( encodeURIComponent( k ) + '=' + encodeURIComponent( all[ k ] ) );
		}
		return CFG.exportUrl + '?' + q.join( '&' );
	}

	/* ============================================================
	   EXPLORADOR — tabela paginada no servidor.

	   Busca com debounce, selects de filtro, paginação. Enquanto a
	   próxima página chega, a atual fica esmaecida no lugar (sem
	   "piscar" vazio), e resposta atrasada de busca antiga é descartada.
	   ============================================================ */
	/* Trabalho adiado até o painel ficar visível: os exploradores
	   consultam o servidor, e não faz sentido pagar por isso numa aba
	   que o usuário talvez nem abra. */
	var pending = [];

	function whenVisible( host, fn ) {
		var panel = host.closest( '.mv-an-panel' );
		if ( ! panel || ! panel.hidden ) return fn();
		pending.push( { panel: panel, fn: fn } );
	}

	function runPending() {
		pending = pending.filter( function ( p ) {
			if ( p.panel.hidden ) return true;
			p.fn();
			return false;
		} );
	}

	function explorer( host, cfg ) {
		var params = { page: 1, per: 25 };
		( cfg.controls || [] ).forEach( function ( c ) { params[ c.name ] = c.value || ''; } );

		var wrap  = el( 'div', { class: 'mv-an-xp' } );
		var bar   = el( 'div', { class: 'mv-an-xp__bar' } );
		var sum   = el( 'div', { class: 'mv-an-xp__sum', 'aria-live': 'polite' } );
		var body  = el( 'div', { class: 'mv-an-xp__body' } );
		var pager = el( 'div', { class: 'mv-an-xp__pager' } );
		var seq   = 0;

		( cfg.controls || [] ).forEach( function ( c ) {
			var id = 'mv-an-' + cfg.action + '-' + c.name;
			bar.appendChild( el( 'label', { class: 'mv-an-sr', for: id }, c.label ) );
			if ( c.type === 'search' ) {
				var inp = el( 'input', { type: 'search', id: id, class: 'mv-an-input', placeholder: c.placeholder || 'Buscar…', autocomplete: 'off' } );
				var t;
				inp.addEventListener( 'input', function () {
					clearTimeout( t );
					t = setTimeout( function () { params[ c.name ] = inp.value.trim(); params.page = 1; load(); }, 320 );
				} );
				bar.appendChild( inp );
			} else {
				var sel = el( 'select', { id: id, class: 'mv-an-select mv-an-select--light' } );
				c.options.forEach( function ( o ) {
					var opt = el( 'option', { value: o[ 0 ] }, o[ 1 ] );
					if ( String( o[ 0 ] ) === String( c.value || '' ) ) opt.selected = true;
					sel.appendChild( opt );
				} );
				sel.addEventListener( 'change', function () { params[ c.name ] = sel.value; params.page = 1; load(); } );
				bar.appendChild( sel );
			}
		} );

		var exp = null;
		if ( cfg.exportType && window.MV_AN_CFG ) {
			exp = el( 'a', { class: 'mv-an-btn mv-an-btn--accent mv-an-xp__export', href: '#' }, 'Exportar CSV' );
			bar.appendChild( exp );
		}

		wrap.appendChild( bar );
		wrap.appendChild( sum );
		wrap.appendChild( body );
		wrap.appendChild( pager );
		host.appendChild( wrap );

		function renderPager( data ) {
			pager.innerHTML = '';
			if ( ! data.total ) return;
			var from = ( data.page - 1 ) * data.per + 1;
			var to   = Math.min( data.total, data.page * data.per );
			pager.appendChild( el( 'span', {}, fmtNum( from ) + '–' + fmtNum( to ) + ' de ' + fmtNum( data.total ) ) );
			var nav  = el( 'span', { class: 'mv-an-xp__nav' } );
			var prev = el( 'button', { type: 'button', class: 'mv-an-btn' }, '‹ Anterior' );
			var next = el( 'button', { type: 'button', class: 'mv-an-btn' }, 'Próxima ›' );
			prev.disabled = data.page <= 1;
			next.disabled = to >= data.total;
			prev.addEventListener( 'click', function () { params.page--; load( true ); } );
			next.addEventListener( 'click', function () { params.page++; load( true ); } );
			nav.appendChild( prev );
			nav.appendChild( next );
			pager.appendChild( nav );
		}

		function load( scroll ) {
			var my = ++seq;
			wrap.classList.add( 'is-loading' );
			if ( exp ) exp.href = exportHref( cfg.exportType, params ) || '#';
			api( cfg.action, params, function ( err, data ) {
				if ( my !== seq ) return;
				wrap.classList.remove( 'is-loading' );
				body.innerHTML = '';
				if ( err ) {
					body.appendChild( el( 'div', { class: 'mv-an-empty' }, err.message ) );
					pager.innerHTML = '';
					sum.textContent = '';
					return;
				}
				sum.textContent = cfg.summary ? cfg.summary( data ) : '';
				if ( ! data.rows.length ) {
					empty( body, cfg.emptyText || 'Nada encontrado com esses filtros.' );
				} else {
					cfg.render( body, data.rows );
				}
				renderPager( data );
				if ( scroll ) wrap.scrollIntoView( { block: 'start', behavior: 'smooth' } );
			} );
		}

		whenVisible( host, load );
	}

	/* ── Itens de um pedido, em lista ───────────────────────── */
	function orderLines( lines ) {
		var ul = el( 'ul', { class: 'mv-an-lines' } );
		( lines || [] ).forEach( function ( l ) {
			var li = el( 'li' );
			li.appendChild( el( 'span', { class: 'mv-an-lines__qty' }, l.qty + '×' ) );
			li.appendChild( el( 'span', { class: 'mv-an-lines__name' }, l.name ) );
			li.appendChild( el( 'span', { class: 'mv-an-lines__val' }, fmtMoney2( l.net ) ) );
			ul.appendChild( li );
		} );
		return ul;
	}

	function orderLink( o ) {
		if ( ! o.url ) return el( 'strong', {}, '#' + o.number );
		return el( 'a', { href: o.url, class: 'mv-an-link', target: '_blank', rel: 'noopener' }, '#' + o.number );
	}

	/* Tabela de pedidos com linha expansível para os itens. */
	function ordersTable( host, rows ) {
		var wrap  = el( 'div', { class: 'mv-an-tablewrap' } );
		var table = el( 'table', { class: 'mv-an-table mv-an-table--orders' } );
		var thead = el( 'thead' );
		var htr   = el( 'tr' );
		[ [ '', '' ], [ 'Pedido', '' ], [ 'Data', '' ], [ 'Cliente', '' ], [ 'Tipo', '' ], [ 'Status', '' ],
		  [ 'Itens', 'is-num' ], [ 'Pagamento', '' ], [ 'Total', 'is-num' ] ].forEach( function ( h ) {
			htr.appendChild( el( 'th', { scope: 'col', class: h[ 1 ] || null }, h[ 0 ] ) );
		} );
		thead.appendChild( htr );
		table.appendChild( thead );

		var tbody = el( 'tbody' );
		rows.forEach( function ( o ) {
			var tr  = el( 'tr' );
			var btn = el( 'button', { type: 'button', class: 'mv-an-expand', 'aria-expanded': 'false', 'aria-label': 'Ver itens do pedido ' + o.number }, '›' );
			var td0 = el( 'td', { class: 'is-expand' } );
			td0.appendChild( btn );
			tr.appendChild( td0 );

			var td = function ( content, cls ) {
				var c = el( 'td', { class: cls || null } );
				if ( content instanceof Node ) c.appendChild( content ); else c.textContent = content == null ? '—' : content;
				tr.appendChild( c );
			};
			td( orderLink( o ) );
			td( o.date, 'is-muted' );
			td( stackCell( custLink( o.customerId, o.customer ),
				o.lifeOrders ? 'Total de ' + plural( o.lifeOrders, 'compra', 'compras' ) : o.email ) );
			td( typeBadge( o.returning ) );
			td( statusBadge( o.status, o.statusLbl ) );
			td( fmtNum( o.items ), 'is-num' );
			td( o.payment || '—', 'is-muted' );
			td( fmtMoney2( o.total ), 'is-num is-strong' );
			tbody.appendChild( tr );

			/* Linha de detalhe: itens exatos + composição do valor. */
			var sub  = el( 'tr', { class: 'mv-an-subrow', hidden: '' } );
			var cell = el( 'td', { colspan: 9 } );
			var grid = el( 'div', { class: 'mv-an-subrow__grid' } );
			grid.appendChild( orderLines( o.lines ) );
			var dl = el( 'dl', { class: 'mv-an-facts' } );
			[
				[ 'Subtotal líquido', fmtMoney2( o.net ) ],
				[ 'Frete', fmtMoney2( o.shipping ) ],
				[ 'Impostos', fmtMoney2( o.tax ) ],
				[ 'Desconto', o.discount ? '− ' + fmtMoney2( o.discount ) : '—' ],
				[ 'Cupons', o.coupons && o.coupons.length ? o.coupons.join( ', ' ) : '—' ],
				[ 'Local', o.location || '—' ]
			].forEach( function ( f ) {
				dl.appendChild( el( 'dt', {}, f[ 0 ] ) );
				dl.appendChild( el( 'dd', {}, f[ 1 ] ) );
			} );
			grid.appendChild( dl );
			cell.appendChild( grid );
			sub.appendChild( cell );
			tbody.appendChild( sub );

			btn.addEventListener( 'click', function () {
				var open = sub.hidden;
				sub.hidden = ! open;
				btn.setAttribute( 'aria-expanded', String( open ) );
				tr.classList.toggle( 'is-open', open );
			} );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		host.appendChild( wrap );
	}

	/* Ordenação fica no servidor (select "Ordenar"): reordenar só a
	   página visível daria a falsa impressão de ordenar a base toda. */
	function noSort( cols ) {
		return cols.map( function ( c ) { c.sortable = false; return c; } );
	}

	function customersTable( host, rows ) {
		buildTable( host, noSort( [
			{ label: 'Cliente', key: 'name', cell: function ( r ) { return stackCell( custLink( r.id, r.name ), r.email ); } },
			{ label: 'Local', key: 'city', muted: true, cell: function ( r ) { return [ r.city, r.state ].filter( Boolean ).join( ' / ' ) || '—'; } },
			{ label: 'Estágio', key: 'segment', cell: function ( r ) { return segBadge( r.segment, r.segLabel ); } },
			{ label: 'Compras', key: 'orders', num: true, cell: function ( r ) { return el( 'strong', {}, fmtNum( r.orders ) ); } },
			{ label: 'Total gasto', key: 'spent', num: true, cell: function ( r ) { return fmtMoney2( r.spent ); } },
			{ label: 'Ticket médio', key: 'avg', num: true, cell: function ( r ) { return fmtMoney2( r.avg ); } },
			{ label: 'Última compra', key: 'days', num: true, sortValue: function ( r ) { return -r.days; },
				cell: function ( r ) { return stackCell( r.last, 'há ' + plural( r.days, 'dia', 'dias' ) ); } },
			{ label: 'Intervalo médio', key: 'interval', num: true,
				cell: function ( r ) { return r.interval == null ? '—' : plural( r.interval, 'dia', 'dias' ); } },
			{ label: 'No período', key: 'periodSpent', num: true,
				cell: function ( r ) { return r.periodOrders ? stackCell( fmtMoney2( r.periodSpent ), plural( r.periodOrders, 'pedido', 'pedidos' ) ) : '—'; } }
		] ), rows );
	}

	/* ============================================================
	   FICHA DO CLIENTE — gaveta lateral
	   ============================================================ */
	var drawer = { root: null, body: null, last: null };

	function initDrawer( root ) {
		drawer.root = root.querySelector( '[data-mv="drawer"]' );
		if ( ! drawer.root ) return;
		drawer.body  = drawer.root.querySelector( '[data-mv="drawer-body"]' );
		drawer.panel = drawer.root.querySelector( '.mv-an-drawer__panel' );

		drawer.root.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-mv="drawer-close"]' ) ) closeDrawer();
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! drawer.root.hidden ) closeDrawer();
		} );

		/* Qualquer nome de cliente em qualquer tabela abre a ficha. */
		root.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-customer]' );
			if ( ! b ) return;
			e.preventDefault();
			openCustomer( +b.getAttribute( 'data-customer' ), b );
		} );
	}

	function closeDrawer() {
		drawer.root.hidden = true;
		document.documentElement.classList.remove( 'mv-an-noscroll' );
		drawer.body.innerHTML = '';
		if ( drawer.last && drawer.last.focus ) drawer.last.focus();
	}

	function openCustomer( id, trigger ) {
		if ( ! drawer.root || ! id ) return;
		drawer.last = trigger || null;
		drawer.root.hidden = false;
		document.documentElement.classList.add( 'mv-an-noscroll' );
		drawer.body.innerHTML = '';
		drawer.body.appendChild( el( 'div', { class: 'mv-an-loading' }, 'Carregando a ficha do cliente…' ) );
		drawer.panel.focus();

		api( 'mv_an_customer', { id: id }, function ( err, c ) {
			if ( drawer.root.hidden ) return;
			drawer.body.innerHTML = '';
			if ( err ) return drawer.body.appendChild( el( 'div', { class: 'mv-an-empty' }, err.message ) );
			renderCustomer( drawer.body, c );
		} );
	}

	function section( host, title ) {
		var s = el( 'section', { class: 'mv-an-dsec' } );
		s.appendChild( el( 'h3', { class: 'mv-an-dsec__title' }, title ) );
		host.appendChild( s );
		return s;
	}

	function renderCustomer( host, c ) {
		var st = c.stats;

		var head = el( 'header', { class: 'mv-an-dhead' } );
		head.appendChild( el( 'h2', { id: 'mv-an-drawer-title', class: 'mv-an-dhead__name' }, c.name ) );
		var meta = [ c.email, c.location ].filter( Boolean ).join( ' · ' );
		if ( meta ) head.appendChild( el( 'p', { class: 'mv-an-dhead__meta' }, meta ) );
		var badges = el( 'div', { class: 'mv-an-dhead__badges' } );
		badges.appendChild( segBadge( c.segment, c.segLabel ) );
		badges.appendChild( el( 'span', { class: 'mv-an-badge mv-an-badge--ghost' },
			c.guest ? 'Compra como convidado' : 'Cadastrado' + ( c.registered ? ' desde ' + c.registered : '' ) ) );
		head.appendChild( badges );
		if ( c.links && c.links.length ) {
			var links = el( 'div', { class: 'mv-an-dhead__links' } );
			c.links.forEach( function ( l ) {
				links.appendChild( el( 'a', { class: 'mv-an-btn', href: l.url, target: '_blank', rel: 'noopener' }, l.label + ' ↗' ) );
			} );
			head.appendChild( links );
		}
		host.appendChild( head );

		/* Números da relação — a pergunta "quantas vezes comprou" é o 1º. */
		var rhythm = { ok: [ 'up', 'No ritmo' ], due: [ 'accent', 'Perto do prazo' ], late: [ 'down', 'Atrasado' ] }[ st.rhythm ];
		var tiles = [
			[ 'Compras', fmtNum( st.orders ), st.orders === 1 ? 'só a primeira' : 'na vida' ],
			[ 'Total gasto', fmtMoney2( st.spent ), st.refunds ? fmtMoney2( st.refunds ) + ' reembolsado' : 'bruto, com frete' ],
			[ 'Ticket médio', fmtMoney2( st.avg ), fmtNum( st.items ) + ' itens no total' ],
			[ '1ª compra', st.first, '' ],
			[ 'Última compra', st.last, st.days == null ? '' : 'há ' + plural( st.days, 'dia', 'dias' ) ],
			[ 'Intervalo médio', st.interval == null ? '—' : plural( st.interval, 'dia', 'dias' ), st.interval == null ? 'precisa de 2+ compras' : 'entre um pedido e outro' ],
			[ 'Próxima compra prevista', st.next || '—', rhythm || '' ]
		];
		var grid = el( 'div', { class: 'mv-an-dstats' } );
		tiles.forEach( function ( t ) {
			var tile = el( 'div', { class: 'mv-an-dstat' } );
			tile.appendChild( el( 'span', { class: 'mv-an-dstat__label' }, t[ 0 ] ) );
			tile.appendChild( el( 'span', { class: 'mv-an-dstat__value' }, t[ 1 ] ) );
			if ( Array.isArray( t[ 2 ] ) ) {
				tile.appendChild( el( 'span', { class: 'mv-an-badge mv-an-badge--' + t[ 2 ][ 0 ] }, t[ 2 ][ 1 ] ) );
			} else if ( t[ 2 ] ) {
				tile.appendChild( el( 'span', { class: 'mv-an-dstat__sub' }, t[ 2 ] ) );
			}
			grid.appendChild( tile );
		} );
		host.appendChild( grid );

		if ( c.monthly && c.monthly.length > 1 ) {
			var sm = section( host, 'Gasto por mês' );
			var ch = el( 'div', { class: 'mv-an-chart' } );
			sm.appendChild( ch );
			barChart( ch, c.monthly, {
				height: 180, maxBar: 28,
				alt: 'Gasto do cliente por mês.',
				series: [ { key: 's1', label: 'Gasto', cls: 's1', color: 'var(--mv-s1)' } ]
			} );
		}

		if ( c.products && c.products.length ) {
			var sp = section( host, 'Produtos que mais compra' );
			buildTable( sp, [
				{ label: 'Produto', key: 'name' },
				{ label: 'Pedidos', key: 'orders', num: true, cell: function ( r ) { return fmtNum( r.orders ); } },
				{ label: 'Unidades', key: 'qty', num: true, cell: function ( r ) { return fmtNum( r.qty ); } },
				{ label: 'Valor', key: 'revenue', num: true, cell: function ( r ) { return fmtMoney2( r.revenue ); } },
				{ label: 'Última vez', key: 'last', muted: true }
			], c.products );
		}

		if ( c.coupons && c.coupons.length ) {
			var sc = section( host, 'Cupons usados' );
			var chips = el( 'div', { class: 'mv-an-chips' } );
			c.coupons.forEach( function ( k ) {
				chips.appendChild( el( 'span', { class: 'mv-an-chip' },
					k.code + ' · ' + plural( k.orders, 'vez', 'vezes' ) + ' · −' + fmtMoney2( k.discount ) ) );
			} );
			sc.appendChild( chips );
		}

		var so = section( host, 'Histórico de compras (' + fmtNum( c.orders.length ) + ')' );
		var list = el( 'ol', { class: 'mv-an-history' } );
		c.orders.forEach( function ( o, i ) {
			var li  = el( 'li', { class: 'mv-an-history__item' } );
			var top = el( 'div', { class: 'mv-an-history__top' } );
			top.appendChild( el( 'span', { class: 'mv-an-history__n' }, ( c.orders.length - i ) + 'ª compra' ) );
			top.appendChild( orderLink( o ) );
			top.appendChild( el( 'span', { class: 'mv-an-history__date' }, o.date ) );
			top.appendChild( statusBadge( o.status, o.statusLbl ) );
			top.appendChild( el( 'strong', { class: 'mv-an-history__total' }, fmtMoney2( o.total ) ) );
			li.appendChild( top );
			li.appendChild( orderLines( o.lines ) );
			list.appendChild( li );
		} );
		so.appendChild( list );
	}

	/* ── Abas ────────────────────────────────────────────────── */
	function initTabs( root ) {
		var tabs = root.querySelectorAll( '.mv-an-tab' );
		if ( ! tabs.length ) return;

		var key = 'mvan.tab';
		function select( id ) {
			Array.prototype.forEach.call( tabs, function ( t ) {
				var on = t.getAttribute( 'data-panel' ) === id;
				t.setAttribute( 'aria-selected', String( on ) );
				t.setAttribute( 'tabindex', on ? '0' : '-1' );
			} );
			Array.prototype.forEach.call( root.querySelectorAll( '.mv-an-panel' ), function ( p ) {
				p.hidden = p.id !== id;
			} );
			/* A aba sobrevive à troca de filtro (que recarrega a página). */
			try { sessionStorage.setItem( key, id ); } catch ( e ) {}
			/* Um painel oculto mede 0 de largura, então o gráfico que
			   estava nele foi desenhado no fallback. Agora que ele tem
			   largura real, redesenha. */
			redrawAll();
			runPending();
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

		var saved = null;
		try { saved = sessionStorage.getItem( key ); } catch ( e ) {}
		var valid = saved && root.querySelector( '.mv-an-tab[data-panel="' + saved + '"]' );
		select( valid ? saved : tabs[ 0 ].getAttribute( 'data-panel' ) );
	}

	/* ============================================================
	   MONTAGEM
	   ============================================================ */
	function build( root, D ) {
		var $ = function ( sel ) { return root.querySelector( sel ); };
		var CFG = window.MV_AN_CFG || {};

		/* ---- KPIs ---- */
		var kpiHost = $( '[data-mv="kpis"]' );
		if ( kpiHost && D.kpis ) {
			D.kpis.forEach( function ( k ) {
				var card = el( 'div', { class: 'mv-an-kpi', title: k.exact || null } );
				card.appendChild( el( 'span', { class: 'mv-an-kpi__label' }, k.label ) );
				var val = el( 'span', { class: 'mv-an-kpi__value' } );
				val.textContent = k.value;
				if ( k.unit ) val.appendChild( el( 'small', {}, ' ' + k.unit ) );
				card.appendChild( val );
				if ( k.spark && k.spark.length > 1 ) card.appendChild( sparkline( k.spark ) );
				var foot = el( 'div', { class: 'mv-an-kpi__foot' } );
				foot.appendChild( deltaBadge( k.delta, k.invert ) );
				foot.appendChild( document.createTextNode( k.note || 'vs. período anterior' ) );
				card.appendChild( foot );
				kpiHost.appendChild( card );
			} );
		}

		var rows = D.series ? D.series.rows : null;

		/* ---- Evolução: atual vs. comparado ---- */
		var tChart = $( '[data-mv="trend-chart"]' );
		var tTable = $( '[data-mv="trend-table"]' );
		if ( tChart && rows ) {
			var metrics = [
				{ id: 'revenue', label: 'Vendas totais', format: fmtMoney2,
					get: function ( r ) { return r.revenue; }, getPrev: function ( r ) { return r.prev.revenue; } },
				{ id: 'net', label: 'Vendas líquidas', format: fmtMoney2,
					get: function ( r ) { return r.net; }, getPrev: function ( r ) { return r.prev.net; } },
				{ id: 'orders', label: 'Pedidos', format: fmtNum, integer: true,
					get: function ( r ) { return r.orders; }, getPrev: function ( r ) { return r.prev.orders; } },
				{ id: 'ticket', label: 'Ticket médio', format: fmtMoney2,
					get: function ( r ) { return r.orders ? r.net / r.orders : 0; },
					getPrev: function ( r ) { return r.prev.orders ? r.prev.net / r.prev.orders : 0; } }
			];
			var chart = lineChart( tChart, rows, { metric: metrics[ 0 ] } );

			var drawTable = function ( m ) {
				tTable.innerHTML = '';
				buildTable( tTable, [
					{ label: D.series.unit ? D.series.unit.charAt( 0 ).toUpperCase() + D.series.unit.slice( 1 ) : 'Período', key: 'label', sortable: false },
					{ label: m.label + ' (atual)', key: 'cur', num: true, sortValue: m.get, cell: function ( r ) { return m.format( m.get( r ) ); } },
					{ label: 'Comparado', key: 'prv', num: true, sortValue: m.getPrev,
						cell: function ( r ) { return m.format( m.getPrev( r ) ) + ( r.prevLabel ? ' (' + r.prevLabel + ')' : '' ); } },
					{ label: 'Variação', key: 'd', num: true,
						sortValue: function ( r ) { var p = m.getPrev( r ); return p ? ( m.get( r ) - p ) / p : 0; },
						cell: function ( r ) { var p = m.getPrev( r ); return deltaBadge( p > 0 ? ( m.get( r ) - p ) / p * 100 : null ); } }
				], rows );
			};
			drawTable( metrics[ 0 ] );

			var seg = $( '[data-mv="trend-metric"]' );
			if ( seg ) {
				metrics.forEach( function ( m, i ) {
					var b = el( 'button', { type: 'button', 'aria-pressed': String( i === 0 ) }, m.label );
					b.addEventListener( 'click', function () {
						Array.prototype.forEach.call( seg.children, function ( o ) { o.setAttribute( 'aria-pressed', 'false' ); } );
						b.setAttribute( 'aria-pressed', 'true' );
						if ( chart ) chart.setMetric( m );
						drawTable( m );
					} );
					seg.appendChild( b );
				} );
			}
			attachToggle( $( '[data-mv="trend-toggle"]' ), tChart, tTable );
		}

		/* ---- Receita: novo vs. recorrente ---- */
		var mChart = $( '[data-mv="monthly-chart"]' );
		var mTable = $( '[data-mv="monthly-table"]' );
		var stackRows = rows || D.monthly;
		if ( mChart && stackRows ) {
			stackedBars( mChart, stackRows, { s1Label: 'Recorrentes', s2Label: 'Novos' } );
			buildTable( mTable, [
				{ label: 'Período', key: 'label', sortable: false },
				{ label: 'Novos', key: 's2', num: true, cell: function ( r ) { return fmtMoney( r.s2 ); } },
				{ label: 'Recorrentes', key: 's1', num: true, cell: function ( r ) { return fmtMoney( r.s1 ); } },
				{ label: 'Total', key: 'total', num: true,
					sortValue: function ( r ) { return r.s1 + r.s2; },
					cell: function ( r ) { return fmtMoney( r.s1 + r.s2 ); } },
				{ label: '% novos', key: 'pct', num: true,
					sortValue: function ( r ) { return ( r.s1 + r.s2 ) ? r.s2 / ( r.s1 + r.s2 ) : 0; },
					cell: function ( r ) { var t = r.s1 + r.s2; return fmtPct( t ? r.s2 / t * 100 : 0 ); } }
			], stackRows );
			attachToggle( $( '[data-mv="monthly-toggle"]' ), mChart, mTable );
		}

		/* ---- Quando compram ---- */
		if ( $( '[data-mv="heatmap"]' ) && D.heatmap ) weekHeatmap( $( '[data-mv="heatmap"]' ), D.heatmap );

		/* ---- Status dos pedidos ---- */
		if ( $( '[data-mv="order-status"]' ) && D.orderStatus ) {
			buildTable( $( '[data-mv="order-status"]' ), [
				/* "Fora dos totais" vai embaixo do status, não numa 5ª coluna:
				   no card de 5/12 a tabela não cabia e a coluna sumia na rolagem. */
				{ label: 'Status', key: 'label', cell: function ( r ) {
					return stackCell( statusBadge( r.status, r.label ), r.counted ? null : 'fora dos totais' );
				} },
				{ label: 'Pedidos', key: 'orders', num: true, cell: function ( r ) { return fmtNum( r.orders ); } },
				{ label: 'Fatia', key: 'pct', num: true, cell: function ( r ) { return shareCell( r.pct ); } },
				{ label: 'Valor', key: 'revenue', num: true, cell: function ( r ) { return fmtMoney( r.revenue ); } }
			], D.orderStatus );
		}

		/* ---- Frequência de compra ---- */
		var fChart = $( '[data-mv="freq-chart"]' );
		var fTable = $( '[data-mv="freq-table"]' );
		if ( fChart && D.frequency ) {
			var fr = D.frequency.map( function ( f ) {
				var o = {}; for ( var k in f ) o[ k ] = f[ k ];
				o.label = f.label + ( f.label === '1' ? ' compra' : ' compras' );
				return o;
			} );
			barChart( fChart, fr, {
				alt: 'Clientes do período por número de compras na vida.',
				integer: true, maxBar: 64,
				format: function ( v ) { return plural( v, 'cliente', 'clientes' ); },
				series: [ { key: 'customers', label: 'Clientes', cls: 's1', color: 'var(--mv-s1)' } ],
				tipExtra: function ( r ) {
					return [ { key: 'Fatia', val: fmtPct( r.pct ) }, { key: 'Gasto médio na vida', val: fmtMoney2( r.avgSpent ) } ];
				}
			} );
			buildTable( fTable, [
				{ label: 'Compras na vida', key: 'label', sortable: false },
				{ label: 'Clientes', key: 'customers', num: true, cell: function ( r ) { return fmtNum( r.customers ); } },
				{ label: 'Fatia', key: 'pct', num: true, cell: function ( r ) { return shareCell( r.pct ); } },
				{ label: 'Gasto total na vida', key: 'spent', num: true, cell: function ( r ) { return fmtMoney( r.spent ); } },
				{ label: 'Gasto médio', key: 'avgSpent', num: true, cell: function ( r ) { return fmtMoney2( r.avgSpent ); } }
			], fr );
			attachToggle( $( '[data-mv="freq-toggle"]' ), fChart, fTable );
		}

		/* ---- Estados ---- */
		if ( $( '[data-mv="geo"]' ) && D.geo ) {
			hBars( $( '[data-mv="geo"]' ), D.geo.map( function ( g ) {
				return { label: g.label, value: g.revenue, customers: g.customers };
			} ), {
				format: function ( r ) { return fmtMoney( r.value ) + ' · ' + plural( r.customers, 'cliente', 'clientes' ); }
			} );
		}

		/* ---- Melhores clientes ---- */
		if ( $( '[data-mv="top-customers"]' ) && D.topCustomers ) {
			buildTable( $( '[data-mv="top-customers"]' ), [
				{ label: 'Cliente', key: 'name', cell: function ( r ) { return stackCell( custLink( r.id, r.name ), r.email ); } },
				{ label: 'Local', key: 'location', muted: true },
				{ label: 'Estágio', key: 'segment', cell: function ( r ) { return segBadge( r.segment, r.segLabel ); } },
				{ label: 'Pedidos no período', key: 'orders', num: true, cell: function ( r ) { return fmtNum( r.orders ); } },
				{ label: 'Receita no período', key: 'revenue', num: true, cell: function ( r ) { return fmtMoney2( r.revenue ); } },
				{ label: 'Compras na vida', key: 'lifeOrders', num: true, cell: function ( r ) { return el( 'strong', {}, fmtNum( r.lifeOrders ) ); } },
				{ label: 'Gasto na vida', key: 'lifeSpent', num: true, cell: function ( r ) { return fmtMoney2( r.lifeSpent ); } },
				{ label: 'Última compra', key: 'last', muted: true, sortable: false }
			], D.topCustomers );
		}

		/* ---- Explorador de clientes ---- */
		if ( $( '[data-mv="customers-explorer"]' ) ) {
			explorer( $( '[data-mv="customers-explorer"]' ), {
				action: 'mv_an_customers',
				exportType: 'customers',
				controls: [
					{ type: 'search', name: 'q', label: 'Buscar cliente', placeholder: 'Nome, e-mail, cidade ou ID' },
					{ type: 'select', name: 'segment', label: 'Estágio', options: [
						[ '', 'Todos os estágios' ], [ 'novo', 'Novos' ], [ 'rec', 'Recorrentes' ],
						[ 'fiel', 'Fiéis' ], [ 'risco', 'Em risco' ], [ 'inativo', 'Inativos' ] ] },
					{ type: 'select', name: 'scope', label: 'Recorte', options: [
						[ 'all', 'Toda a base' ], [ 'period', 'Compraram no período' ] ], value: 'all' },
					{ type: 'select', name: 'sort', label: 'Ordenar', value: 'spent', options: [
						[ 'spent', 'Maior gasto' ], [ 'orders', 'Mais compras' ], [ 'last', 'Compra mais recente' ],
						[ 'period', 'Maior gasto no período' ], [ 'first', 'Cliente mais antigo' ], [ 'name', 'Nome A–Z' ] ] }
				],
				summary: function ( d ) {
					return plural( d.total, 'cliente', 'clientes' ) + ' · ' + fmtMoney( d.spent ) + ' gastos · ' +
						plural( d.orders, 'pedido', 'pedidos' ) + ( d.total ? ' · média de ' + fmtDec( d.orders / d.total ) + ' por cliente' : '' );
				},
				emptyText: 'Nenhum cliente com esses filtros.',
				render: customersTable
			} );
		}

		/* ---- Explorador de pedidos ---- */
		if ( $( '[data-mv="orders-explorer"]' ) ) {
			var stOpts = [ [ '', 'Todos os status' ] ];
			( CFG.statuses || [] ).forEach( function ( s ) {
				stOpts.push( [ s, ( CFG.statusLabels && CFG.statusLabels[ s ] ) || s ] );
			} );
			if ( ! CFG.statuses && D.orderStatus ) {
				D.orderStatus.forEach( function ( s ) { if ( s.counted ) stOpts.push( [ s.status, s.label ] ); } );
			}
			explorer( $( '[data-mv="orders-explorer"]' ), {
				action: 'mv_an_orders',
				exportType: 'orders',
				controls: [
					{ type: 'search', name: 'q', label: 'Buscar pedido', placeholder: 'Nº do pedido, cliente, e-mail ou produto' },
					{ type: 'select', name: 'status', label: 'Status', options: stOpts },
					{ type: 'select', name: 'type', label: 'Tipo de cliente', options: [
						[ '', 'Novos e recorrentes' ], [ 'new', 'Só 1ª compra' ], [ 'returning', 'Só recorrentes' ] ] },
					{ type: 'select', name: 'sort', label: 'Ordenar', value: 'date', options: [
						[ 'date', 'Mais recentes' ], [ 'total', 'Maior valor' ], [ 'items', 'Mais itens' ] ] }
				],
				summary: function ( d ) {
					return plural( d.total, 'pedido', 'pedidos' ) + ' · ' + fmtMoney2( d.revenue ) + ' · ' +
						plural( d.items, 'item', 'itens' ) + ( d.total ? ' · ' + fmtDec( d.items / d.total ) + ' por pedido' : '' );
				},
				emptyText: 'Nenhum pedido com esses filtros.',
				render: ordersTable
			} );
		}

		/* ---- Pagamentos ---- */
		if ( $( '[data-mv="payments"]' ) && D.payments ) {
			buildTable( $( '[data-mv="payments"]' ), [
				{ label: 'Forma', key: 'label' },
				{ label: 'Pedidos', key: 'orders', num: true, cell: function ( r ) { return fmtNum( r.orders ); } },
				{ label: 'Receita', key: 'revenue', num: true, cell: function ( r ) { return fmtMoney( r.revenue ); } },
				{ label: 'Ticket', key: 'ticket', num: true, cell: function ( r ) { return fmtMoney2( r.ticket ); } },
				{ label: 'Fatia', key: 'share', num: true, cell: function ( r ) { return shareCell( r.share ); } }
			], D.payments );
		}

		/* ---- Cupons ---- */
		if ( $( '[data-mv="coupons"]' ) && D.coupons ) {
			if ( ! D.coupons.length ) empty( $( '[data-mv="coupons"]' ), 'Nenhum cupom usado no período.' );
			else buildTable( $( '[data-mv="coupons"]' ), [
				{ label: 'Cupom', key: 'code', cell: function ( r ) { return el( 'code', { class: 'mv-an-code' }, r.code ); } },
				{ label: 'Pedidos', key: 'orders', num: true, cell: function ( r ) { return fmtNum( r.orders ); } },
				{ label: 'Desconto', key: 'discount', num: true, cell: function ( r ) { return fmtMoney( r.discount ); } },
				{ label: 'Receita', key: 'revenue', num: true, cell: function ( r ) { return fmtMoney( r.revenue ); } },
				{ label: '1ª compra', key: 'firstPct', num: true, cell: function ( r ) { return fmtPct( r.firstPct ); } }
			], D.coupons );
		}

		/* ---- Comparativo novo vs. recorrente ----
		   Sem ordenação: são indicadores de unidades diferentes
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
				{ label: 'Preço médio', key: 'avg', num: true,
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
					return el( 'span', { class: 'mv-an-badge mv-an-badge--' + ( r.role === 'Entrada' ? 'accent' : 'ghost' ) }, r.role );
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
			if ( ! D.gaps.length ) empty( gapHost );
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
				{ label: 'Cliente', key: 'customer', cell: function ( r ) { return custLink( r.customerId, r.customer ); } },
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
				[ 'Datas por', D.meta.dateType ],
				[ 'Atualizado', D.meta.updated ]
			].forEach( function ( p ) {
				if ( ! p[ 1 ] ) return;
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

		initAdminControls( root );
		initDrawer( root );

		/* build() já ignora cada bloco cujo dado não veio, então roda
		   mesmo quando só há "meta" (tabelas de lookup ainda vazias). */
		var D = window.MV_AN_DATA;
		if ( D ) build( root, D );

		/* Abas por último: a seleção redesenha os gráficos já montados
		   na largura real do painel visível. */
		initTabs( root );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	/* Exposto para o preview e para testes manuais. */
	window.MVAN = {
		stackedBars: stackedBars, barChart: barChart, lineChart: lineChart, hBars: hBars,
		cohort: cohort, weekHeatmap: weekHeatmap, buildTable: buildTable, openCustomer: openCustomer
	};
} )();
