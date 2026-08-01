export const SITE_CONFIG = {
    name: 'Erlebnishof Auszeit',
    canonicalUrl: 'https://erlebnishof-auszeit.de/',
    timeZone: 'Europe/Berlin',
    address: {
        street: 'Bachhofweg 2a',
        postalCode: '93170',
        locality: 'Bernhardswald',
        country: 'DE',
        latitude: 49.091223,
        longitude: 12.239007,
    },
    contact: {
        phoneDisplay: '09407 / 72 49 620',
        phoneHref: 'tel:+4994077249620',
        mobileDisplay: '0176 64941923',
        mobileHref: 'tel:+4917664941923',
        email: 'hallo@erlebnishof-auszeit.de',
    },
    links: {
        directions: 'https://maps.app.goo.gl/JGKZG3YQoqbwU8hF8',
        currentMenu: 'https://www.google.com/maps/search/?api=1&query=Erlebnishof+Auszeit&query_place_id=ChIJb7MChkPpn0cRKvt0lI2PDfA',
        googleProfile: 'https://www.google.com/maps/search/?api=1&query=Erlebnishof+Auszeit&query_place_id=ChIJb7MChkPpn0cRKvt0lI2PDfA',
        instagram: 'https://www.instagram.com/erlebnishof_auszeit/',
        facebook: 'https://www.facebook.com/profile.php?id=61581214677929',
    },
    regularHours: {
        cafe: {
            days: [0, 5, 6],
            opens: '08:30',
            closes: '17:00',
        },
        shop: {
            days: [0, 1, 2, 3, 4, 5, 6],
            winter: {months: [9, 10, 11, 12, 1, 2, 3], opens: '08:00', closes: '19:00'},
            summer: {months: [4, 5, 6, 7, 8], opens: '08:00', closes: '21:00'},
        },
    },
};

