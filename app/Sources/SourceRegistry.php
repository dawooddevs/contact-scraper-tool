<?php

declare(strict_types=1);

namespace App\Sources;

use App\Api\ApiException;
use App\Core\Settings;

/**
 * Resolves source keys to Source instances and exposes the country catalogue.
 */
final class SourceRegistry
{
    /** @var array<string, Source>|null */
    private static ?array $instances = null;

    /** Business categories offered as suggestions in the wizard. */
    public const CATEGORY_SUGGESTIONS = [
        'Restaurants', 'Cafes', 'Bars', 'Bakeries', 'Plumbers', 'Electricians', 'Builders', 'Roofers', 'Painters',
        'Carpenters', 'Landscapers', 'Cleaners', 'Pest Control', 'Locksmiths', 'Mechanics', 'Car Dealers', 'Dentists',
        'Doctors', 'Physiotherapists', 'Chiropractors', 'Veterinarians', 'Lawyers', 'Accountants', 'Mortgage Brokers',
        'Insurance', 'Real Estate Agents', 'Hair Salons', 'Barbers', 'Beauty Salons', 'Nail Salons', 'Massage', 'Spas',
        'Gyms', 'Yoga Studios', 'Personal Trainers', 'Photographers', 'Wedding Planners', 'Florists', 'Hotels',
        'Childcare', 'Tutors', 'Driving Schools', 'Pet Groomers', 'Web Designers', 'Marketing Agencies', 'Printers',
        'Solar', 'Air Conditioning', 'Concreters', 'Fencing', 'Pool Services', 'Tilers', 'Removalists', 'Movers',
        'Storage', 'Optometrists', 'Pharmacies', 'Tattoo', 'Caterers', 'Food Trucks',
    ];

    /** @return array<string, Source> */
    public static function all(): array
    {
        if (self::$instances === null) {
            self::$instances = [];
            $map = require APP_ROOT . '/app/config/sources.php';
            foreach ($map as $key => $class) {
                if (class_exists($class)) {
                    $instance = new $class();
                    if ($instance instanceof Source) {
                        self::$instances[$key] = $instance;
                    }
                }
            }
        }
        return self::$instances;
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function get(string $key): Source
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new ApiException("Unknown source \"{$key}\".", 422);
        }
        return $all[$key];
    }

    public static function countries(): array
    {
        return require APP_ROOT . '/app/config/countries.php';
    }

    /** Everything the UI needs to render countries, sources and their settings. */
    public static function catalogue(): array
    {
        $countries = [];
        foreach (self::countries() as $code => $country) {
            $sources = [];
            foreach ($country['sources'] as $key) {
                if (!self::has($key)) {
                    continue;
                }
                $source = self::get($key);
                $sources[] = array_merge($source->info(), [
                    'key' => $key,
                    'settings' => Settings::source($key),
                ]);
            }
            $countries[] = [
                'code' => $code,
                'name' => $country['name'],
                'locations' => $country['locations'] ?? [],
                'sources' => $sources,
            ];
        }
        return [
            'countries' => $countries,
            'categories' => self::CATEGORY_SUGGESTIONS,
        ];
    }
}
