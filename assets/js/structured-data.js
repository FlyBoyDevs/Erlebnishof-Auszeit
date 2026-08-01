import {SITE_CONFIG} from './config.js';

const SCHEMA_DAYS = [
    'https://schema.org/Sunday',
    'https://schema.org/Monday',
    'https://schema.org/Tuesday',
    'https://schema.org/Wednesday',
    'https://schema.org/Thursday',
    'https://schema.org/Friday',
    'https://schema.org/Saturday',
];

function regularHours(target, year) {
    if (target === 'cafe') {
        const hours = SITE_CONFIG.regularHours.cafe;
        return [{
            '@type': 'OpeningHoursSpecification',
            dayOfWeek: hours.days.map((day) => SCHEMA_DAYS[day]),
            opens: hours.opens,
            closes: hours.closes,
        }];
    }

    const allDays = SITE_CONFIG.regularHours.shop.days.map((day) => SCHEMA_DAYS[day]);
    const winter = SITE_CONFIG.regularHours.shop.winter;
    const summer = SITE_CONFIG.regularHours.shop.summer;
    return [
        {
            '@type': 'OpeningHoursSpecification',
            dayOfWeek: allDays,
            opens: winter.opens,
            closes: winter.closes,
            validFrom: `${year}-01-01`,
            validThrough: `${year}-03-31`,
        },
        {
            '@type': 'OpeningHoursSpecification',
            dayOfWeek: allDays,
            opens: summer.opens,
            closes: summer.closes,
            validFrom: `${year}-04-01`,
            validThrough: `${year}-08-31`,
        },
        {
            '@type': 'OpeningHoursSpecification',
            dayOfWeek: allDays,
            opens: winter.opens,
            closes: winter.closes,
            validFrom: `${year}-09-01`,
            validThrough: `${year}-12-31`,
        },
    ];
}

function specialHours(target, exceptions) {
    return exceptions
        .filter((exception) => exception.target === target || exception.target === 'both')
        .map((exception) => ({
            '@type': 'OpeningHoursSpecification',
            validFrom: exception.startDate,
            validThrough: exception.endDate,
            opens: exception.closed ? '00:00' : exception.opens,
            closes: exception.closed ? '00:00' : exception.closes,
        }));
}

function address() {
    return {
        '@type': 'PostalAddress',
        streetAddress: SITE_CONFIG.address.street,
        postalCode: SITE_CONFIG.address.postalCode,
        addressLocality: SITE_CONFIG.address.locality,
        addressCountry: SITE_CONFIG.address.country,
    };
}

function geo() {
    return {
        '@type': 'GeoCoordinates',
        latitude: SITE_CONFIG.address.latitude,
        longitude: SITE_CONFIG.address.longitude,
    };
}

export function updateStructuredData(snapshot) {
    const existing = document.getElementById('business-structured-data');
    existing?.remove();

    const nowYear = Number(new Intl.DateTimeFormat('en', {
        timeZone: SITE_CONFIG.timeZone,
        year: 'numeric',
    }).format(new Date()));
    const organizationId = `${SITE_CONFIG.canonicalUrl}#erlebnishof`;
    const cafeId = `${SITE_CONFIG.canonicalUrl}#hofcafe`;
    const shopId = `${SITE_CONFIG.canonicalUrl}#hofladen`;
    const common = {
        url: SITE_CONFIG.canonicalUrl,
        telephone: SITE_CONFIG.contact.phoneHref.replace('tel:', ''),
        email: SITE_CONFIG.contact.email,
        address: address(),
        geo: geo(),
    };

    const graph = {
        '@context': 'https://schema.org',
        '@graph': [
            {
                '@type': 'LocalBusiness',
                '@id': organizationId,
                name: SITE_CONFIG.name,
                url: SITE_CONFIG.canonicalUrl,
                department: [{'@id': cafeId}, {'@id': shopId}],
                sameAs: [
                    SITE_CONFIG.links.googleProfile,
                    SITE_CONFIG.links.instagram,
                    SITE_CONFIG.links.facebook,
                ],
            },
            {
                '@type': 'CafeOrCoffeeShop',
                '@id': cafeId,
                name: `${SITE_CONFIG.name} Hofcafé`,
                parentOrganization: {'@id': organizationId},
                ...common,
                openingHoursSpecification: regularHours('cafe', nowYear),
                specialOpeningHoursSpecification: specialHours('cafe', snapshot.exceptions),
            },
            {
                '@type': 'Store',
                '@id': shopId,
                name: `${SITE_CONFIG.name} Hofladen`,
                parentOrganization: {'@id': organizationId},
                ...common,
                openingHoursSpecification: regularHours('shop', nowYear),
                specialOpeningHoursSpecification: specialHours('shop', snapshot.exceptions),
            },
        ],
    };

    const script = document.createElement('script');
    script.type = 'application/ld+json';
    script.id = 'business-structured-data';
    script.textContent = JSON.stringify(graph);
    document.head.append(script);
}

export function clearStructuredData() {
    document.getElementById('business-structured-data')?.remove();
}
