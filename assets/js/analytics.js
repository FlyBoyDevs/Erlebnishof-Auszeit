const PRODUCTION_HOST = 'erlebnishof-auszeit.de';

export function loadProductionAnalytics() {
    if (location.protocol !== 'https:' || location.hostname !== PRODUCTION_HOST) return;
    if (document.getElementById('goatcounter-script')) return;

    const script = document.createElement('script');
    script.id = 'goatcounter-script';
    script.async = true;
    script.src = 'https://gc.zgo.at/count.js';
    script.dataset.goatcounter = 'https://flyboydevs.goatcounter.com/count';
    document.head.append(script);
}
