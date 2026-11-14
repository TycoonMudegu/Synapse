<?php

declare(strict_types=1);

namespace App\Services\Discovery;

final class LocationMatcher
{
    private const KENYAN_PLACES = [
        'Nairobi',
        'Mombasa',
        'Kisumu',
        'Nakuru',
        'Eldoret',
        'Thika',
        'Kitale',
        'Nyeri',
        'Naivasha',
        'Baringo',
        'Bomet',
        'Bungoma',
        'Busia',
        'Elgeyo Marakwet',
        'Embu',
        'Garissa',
        'Homa Bay',
        'Isiolo',
        'Kajiado',
        'Kakamega',
        'Kericho',
        'Kiambu',
        'Kilifi',
        'Kirinyaga',
        'Kisii',
        'Kitui',
        'Kwale',
        'Laikipia',
        'Lamu',
        'Machakos',
        'Makueni',
        'Mandera',
        'Marsabit',
        'Meru',
        'Migori',
        'Muranga',
        'Nandi',
        'Narok',
        'Nyamira',
        'Nyandarua',
        'Samburu',
        'Siaya',
        'Taita Taveta',
        'Tana River',
        'Tharaka Nithi',
        'Trans Nzoia',
        'Turkana',
        'Uasin Gishu',
        'Vihiga',
        'Wajir',
        'West Pokot',
        'Kitengela',
        'Nanyuki',
        'Kerugoya',
        'Karatina',
        'Chuka',
    ];

    public static function matches(string $location, string $preference): bool
    {
        if (str_contains(mb_strtolower($location), mb_strtolower(trim($preference)))) {
            return true;
        }
        if (mb_strtolower(trim($preference)) !== 'kenya') {
            return false;
        }
        if (
            preg_match(
                '/\b(?:Uganda|Tanzania|Nigeria|Somalia|Rwanda|Ethiopia|South Africa|United Kingdom|United States)\b/iu',
                $location,
            )
        ) {
            return false;
        }
        foreach (self::KENYAN_PLACES as $place) {
            if (preg_match('/\b' . preg_quote($place, '/') . '\b/iu', $location)) {
                return true;
            }
        }
        return false;
    }
}
