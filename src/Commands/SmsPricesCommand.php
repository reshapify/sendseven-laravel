<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands;

use Illuminate\Console\Command;
use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Data\SmsPricelistRow;
use Reshapify\SendSeven\Exceptions\SendSevenException;

/**
 * SMS prices per country, per segment, as SendSeven bills them.
 */
final class SmsPricesCommand extends Command
{
    protected $signature = 'sendseven:sms-prices {country? : ISO code or name, e.g. DE or Germany}';

    protected $description = 'Show SendSeven SMS prices per country';

    public function handle(Client $sendseven): int
    {
        try {
            $pricelist = $sendseven->sms()->pricelist();
        } catch (SendSevenException $sendSevenException) {
            $this->components->error($sendSevenException->getMessage());

            return self::FAILURE;
        }

        $country = $this->argument('country');
        $rows = array_filter(
            $pricelist->countries,
            static fn (SmsPricelistRow $row): bool => ! is_string($country) || $country === ''
                || strcasecmp($row->iso, $country) === 0
                || stripos($row->name, $country) !== false,
        );

        if ($rows === []) {
            $this->components->warn('No country matches '.(is_string($country) ? $country : '').'.');

            return self::FAILURE;
        }

        $this->table(
            ['Country', 'ISO', 'Prefix', 'Per segment (EUR)', 'Supported'],
            array_map(static fn (SmsPricelistRow $row): array => [
                $row->name,
                $row->iso,
                $row->prefix,
                $row->totalPerSegmentEur,
                match ($row->isSupported) {
                    true => 'yes',
                    false => 'no',
                    null => '?',
                },
            ], array_values($rows)),
        );

        $this->line('Long messages bill as several segments. '.($pricelist->lastUpdated === null ? '' : "Prices as of {$pricelist->lastUpdated}."));

        return self::SUCCESS;
    }
}
