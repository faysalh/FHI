<style>
    .pda-subnav { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px; }
    .pda-subnav a, .pda-btn {
        display: inline-flex; align-items: center; justify-content: center;
        min-height: 44px; padding: 8px 14px; border-radius: 10px;
        background: #eef2ff; color: #312e81; text-decoration: none; font-weight: 600;
        border: 1px solid #c7d2fe;
    }
    .pda-subnav a.is-active, .pda-btn--primary { background: #4f46e5; color: #fff; border-color: #4338ca; }
    .pda-card {
        display: block; width: 100%; text-align: left; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px;
        margin-bottom: 10px; background: #fff; color: inherit; font: inherit;
    }
    .pda-card strong { display: block; font-size: 16px; }
    .pda-meta { color: #64748b; font-size: 13px; margin-top: 4px; }
    .pda-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
    .pda-search { display: flex; gap: 8px; margin-bottom: 12px; min-width: 0; max-width: 100%; }
    .pda-search input { flex: 1 1 auto; min-width: 0; width: auto; max-width: 100%; box-sizing: border-box; }
    .pda-search .pda-btn { flex: 0 0 auto; }
    .pda-catalog { display: grid; grid-template-columns: repeat(auto-fill, minmax(148px, 1fr)); gap: 10px; min-width: 0; }
    .pda-product {
        display: flex; flex-direction: column; min-width: 0; padding: 8px;
        border: 1px solid #e2e8f0; border-radius: 14px; background: #fff;
        color: inherit; text-decoration: none;
    }
    a.pda-product { cursor: pointer; }
    .pda-product__photo { display: block; width: 100%; }
    .pda-product__img {
        display: block; width: 100%; aspect-ratio: 1; object-fit: contain;
        background: #f8fafc; border-radius: 10px;
    }
    .pda-product__img--empty { box-shadow: inset 0 0 0 1px #e2e8f0; }
    .pda-product strong {
        direction: rtl; unicode-bidi: isolate; margin-top: 8px; font-size: 14px; line-height: 1.3;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .pda-product__price { margin-top: 4px; font-weight: 800; font-size: 15px; }
    .pda-product__meta { margin-top: 2px; color: #64748b; font-size: 12px; line-height: 1.3; overflow-wrap: anywhere; }
    .pda-line input, form.js-pda-locate input, form.js-pda-locate select { width: 100%; max-width: 100%; box-sizing: border-box; }
    .pda-pos > section { min-width: 0; }
    .pda-finder { min-width: 0; margin-bottom: 8px; }
    .pda-finder__input {
        display: block; width: 100%; max-width: 100%; box-sizing: border-box;
        min-height: 44px; margin: 4px 0 6px;
    }
    .pda-cats {
        display: flex; flex-wrap: nowrap; gap: 6px; max-width: 100%; margin: 0;
        overflow-x: auto; overscroll-behavior-x: contain; -webkit-overflow-scrolling: touch;
    }
    .pda-cat {
        flex: 0 0 auto; min-height: 36px; padding: 6px 12px; border-radius: 999px;
        border: 1px solid #c7d2fe; background: #fff; color: #312e81;
        font: inherit; font-size: 14px; font-weight: 700; white-space: nowrap; cursor: pointer;
    }
    .pda-cat.is-on { background: #4f46e5; color: #fff; border-color: #4338ca; }
    .pda-line { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; margin-bottom: 8px; background: #fff; }
    .pda-line__head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
    .pda-line__head strong { direction: rtl; unicode-bidi: isolate; text-align: right; line-height: 1.3; }
    .pda-line__sum { font-weight: 800; white-space: nowrap; }
    .pda-line--over { border-color: #fdba74; background: #fff7ed; }
    .pda-locate-status { min-height: 1.2em; color: #9a3412; font-size: 14px; }
    .pda-total { font-size: 22px; font-weight: 800; margin: 8px 0; }
    .pda-pos { display: flex; flex-direction: column; gap: 12px; }
    .pda-grid-wrap { max-height: 52vh; overflow: auto; padding-right: 2px; container-type: inline-size; }
    .pda-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px; }
    .pda-tile[hidden] { display: none !important; }
    .pda-tile {
        display: grid; grid-template-columns: 68px minmax(0, 1fr) 76px; align-items: center; column-gap: 8px;
        width: 100%; min-height: 0; padding: 6px; border-radius: 12px; border: 1px solid #e2e8f0;
        background: #fff; color: inherit; font: inherit; text-align: right; cursor: pointer;
    }
    .pda-tile:active { background: #f8fafc; }
    .pda-tile.is-picked { border-color: #6366f1; background: #f5f3ff; }
    .pda-tile--locked { cursor: not-allowed; }
    .pda-tile--locked .pda-tile__stock b { color: #94a3b8; }
    .pda-stock-note { margin: 0 0 8px; color: #b45309; font-size: 13px; font-weight: 700; }
    .pda-tile__stock {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
        text-align: center; line-height: 1;
    }
    .pda-tile__stock b { font-size: 18px; font-weight: 800; color: #0f766e; font-variant-numeric: tabular-nums; }
    .pda-tile__stock small { font-size: 10px; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; color: #64748b; }
    .pda-tile__stock.is-out b { color: #94a3b8; }
    .pda-tile__stock.is-neg b { color: #c2410c; }
    .pda-tile__copy {
        min-width: 0; display: flex; flex-direction: column; align-items: flex-end; justify-content: center; gap: 3px;
        text-align: right;
    }
    .pda-tile__photo { position: relative; width: 76px; height: 76px; }
    .pda-tile__img {
        width: 76px; height: 76px; object-fit: contain; background: #f8fafc;
        border-radius: 10px; margin: 0; display: block;
    }
    .pda-tile__img--empty { background: #f8fafc; box-shadow: inset 0 0 0 1px #e2e8f0; }
    .pda-tile strong {
        direction: rtl; unicode-bidi: isolate; font-size: 15px; line-height: 1.25; max-width: 100%;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .pda-tile__price {
        max-width: 100%; font-weight: 800; font-size: 14px; line-height: 1.2;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .pda-tile__unit { color: #64748b; font-weight: 600; font-size: 12px; margin-inline-start: 4px; }
    .pda-tile__badge {
        position: absolute; top: 4px; right: 4px; min-width: 22px; height: 22px; padding: 0 6px;
        border-radius: 999px; background: #4f46e5; color: #fff; font-size: 12px; font-weight: 700;
        display: none; align-items: center; justify-content: center; z-index: 1;
    }
    .pda-tile__badge.is-on { display: inline-flex; }
    .pda-cart {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 12px;
        max-height: 42vh; overflow: auto;
    }
    .pda-cart__head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .pda-cart__head h2 { margin: 0; }
    .pda-cart__count {
        min-width: 24px; height: 24px; padding: 0 7px; border-radius: 999px;
        background: #eef2ff; color: #3730a3; font-size: 13px; font-weight: 800;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .pda-cart__count:empty { display: none; }
    .pda-qty { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
    .pda-qty button { width: 44px; height: 44px; padding: 0; }
    .pda-qty input { width: 72px; text-align: center; }
    .pda-cart .pda-btn--primary { width: 100%; }
    @container (min-width: 720px) {
        .pda-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 420px) {
        .pda-tile { grid-template-columns: 56px minmax(0, 1fr) 64px; column-gap: 6px; }
        .pda-tile__photo, .pda-tile__img { width: 64px; height: 64px; }
        .pda-tile__stock b { font-size: 16px; }
    }
    @media (min-width: 700px) {
        .pda-pos { display: grid; grid-template-columns: minmax(0, 1fr) 268px; align-items: start; gap: 12px; }
        .pda-grid-wrap, .pda-cart { max-height: calc(100vh - 250px); }
        .pda-cart { position: sticky; top: 12px; }
    }
    .pda-sell { display: flex; flex-direction: column; gap: 8px; margin: 12px 0; }
    .pda-sell form { margin: 0; }
    .pda-btn--sell { width: 100%; min-height: 52px; font-size: 18px; }
    .pda-sell__more { display: flex; gap: 8px; }
    .pda-sell__more form { flex: 1 1 0; }
    .pda-sell__more .pda-btn { width: 100%; }
    .pda-sale-back { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin: 0 0 10px; }
    .pda-sale-back a { font-weight: 700; text-decoration: none; }
    .pda-sale-back a:first-child { font-size: 18px; color: inherit; }
    .pda-sale-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .pda-sale-bar .pda-total { margin: 0; font-size: 18px; }
    .pda-sale-bar .pda-locate-status { flex: 1 0 100%; min-height: 0; margin: 0; }
    .pda-sale-bar .pda-locate-status:empty { display: none; }
    .pda-sale-bar .pda-btn--primary { margin-left: auto; min-height: 44px; }
    @media (max-width: 699px) {
        .pda-sale { padding-bottom: 108px; }
        .pda-sale .pda-grid-wrap { max-height: none; }
        .pda-cart--sale {
            position: fixed; left: 8px; right: 8px; bottom: 0; z-index: 40;
            max-height: none; margin: 0; border-radius: 16px 16px 0 0;
            padding-bottom: calc(10px + env(safe-area-inset-bottom));
            box-shadow: 0 -8px 24px rgba(15, 23, 42, 0.12);
        }
        .pda-cart--sale .pda-cart__sheet { display: none; }
        .pda-cart--sale.is-open .pda-cart__sheet { display: block; max-height: 46vh; overflow: auto; margin-bottom: 8px; }
    }
</style>
