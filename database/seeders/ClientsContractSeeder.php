<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientLocation;
use App\Models\ClientPhone;
use App\Models\Contract;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class ClientsContractSeeder extends Seeder
{
    public function run(): void
    {
        $createdBy = User::query()->min('id') ?? 1;
        $department = Department::query()->where('name_en', 'AC Maintenance')->first()
            ?? Department::query()->create([
                'name_en' => 'AC Maintenance',
                'name_ar' => 'صيانة التكييف',
                'is_service' => true,
                'created_by' => $createdBy,
            ]);

        $created = ['clients' => 0, 'phones' => 0, 'locations' => 0, 'contracts' => 0];
        $errors = [];

        foreach (require __DIR__.'/data/clients_contracts.php' as $i => $row) {
            try {
                [$contract, $phones, $locations, $newClient, $newContract] = $this->importRow($row, $department, $createdBy);
                if ($newClient) {
                    $created['clients']++;
                }
                if ($newContract) {
                    $created['contracts']++;
                }
                $created['phones'] += $phones;
                $created['locations'] += $locations;
            } catch (\Throwable $e) {
                $errors[$i] = $row['name'].' | '.$row['contract_no'].' | '.$e->getMessage();
            }
        }

        $this->show([
            "Imported clients  : {$created['clients']}",
            "Imported phones   : {$created['phones']}",
            "Imported locations: {$created['locations']}",
            "Imported contracts: {$created['contracts']}",
        ]);

        if ($errors) {
            $this->show('Errors:');
            foreach ($errors as $line) {
                $this->show('  - '.$line);
            }
        }
    }

    /**
     * @return array{0: Contract, 1: int, 2: int, 3: bool, 4: bool}
     */
    private function importRow(array $row, Department $department, int $createdBy): array
    {
        $client = Client::query()->firstOrCreate(
            ['name' => trim($row['name'])],
            ['created_by' => $createdBy]
        );

        $phones = $this->importPhone($client, $row['phone'], $createdBy);
        [$location, $newLocations] = $this->importLocation($client, $row, $createdBy);

        [$start, $end] = $this->resolveDates($row);
        if (! $start || ! $end) {
            throw new \RuntimeException("Cannot resolve contract dates for [{$row['contract_no']}].");
        }

        [$start, $end] = $start->gt($end) ? [$end, $start] : [$start, $end];

        ['wstart' => $wstart, 'wend' => $wend] = $this->resolveWarrantyDates($row, $start);

        $attributes = [
            'client_id' => $client->id,
            'department_id' => $department->id,
            'type' => $row['type'],
            'reference_no' => (string) $row['contract_no'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_amount' => (int) $row['amount'],
        ];

        $values = [
            'location_id' => $location->id,
            'includes_spare_parts' => $this->includesSpareParts($row),
            'includes_compressor_warranty' => (bool) ($wstart && $wend),
            'compressor_warranty_start' => $wstart?->toDateString(),
            'compressor_warranty_end' => $wend?->toDateString(),
            'payment_count' => 0,
            'planned_visits' => 0,
            'status' => $row['status'],
            'created_by' => $createdBy,
        ];

        $contract = Contract::query()
            ->where('client_id', $client->id)
            ->where('department_id', $department->id)
            ->where('type', $row['type'])
            ->where('reference_no', (string) $row['contract_no'])
            ->whereDate('start_date', $start)
            ->first();

        if ($contract) {
            $contract->update($values);
        } else {
            $contract = Contract::query()->create($attributes + $values);
        }

        return [$contract, $phones, $newLocations, $client->wasRecentlyCreated, $contract->wasRecentlyCreated];
    }

    private function importPhone(Client $client, ?string $phone, int $createdBy): int
    {
        $digits = (string) $phone;
        $digits = preg_replace('/\D/', '', $digits) ?: '';
        if ($digits === '' || $digits === '0') {
            return 0;
        }

        $creating = ClientPhone::query()->firstOrCreate(
            ['client_id' => $client->id, 'phone' => $digits],
            ['country_code' => '+965', 'is_primary' => ! $client->phones()->exists(), 'created_by' => $createdBy]
        );

        return $creating->wasRecentlyCreated ? 1 : 0;
    }

    /**
     * @return array{0: ClientLocation, 1: int}
     */
    private function importLocation(Client $client, array $row, int $createdBy): array
    {
        $attributes = [
            'client_id' => $client->id,
            'area' => (string) $row['area'],
            'block' => (string) $row['block'],
            'street' => (string) $row['street'],
            'building' => $row['building'] ?: null,
            'avenue' => $row['avenue'] ?: null,
        ];

        $extras = trim('رقم العقد: '.($row['contract_no'] ?? '').' | '.($row['notes'] ?? ''), " |\t\n\r\0\x0B");

        $location = ClientLocation::query()->firstOrCreate($attributes, [
            'label' => $attributes['area'] ?: 'موقع',
            'country' => 'Kuwait',
            'extras' => $extras ?: null,
            'created_by' => $createdBy,
        ]);

        return [$location, $location->wasRecentlyCreated ? 1 : 0];
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function resolveDates(array $row): array
    {
        $start = $this->carbon($row['start']);
        $end = $this->carbon($row['end']);

        if (! $start && $end) {
            $start = $end->subDays(364);
        } elseif ($start && ! $end) {
            $end = $start->addDays(364);
        } elseif (! $start && ! $end && $this->carbon($row['wstart']) && $this->carbon($row['wend'])) {
            $start = $this->carbon($row['wstart']);
            $end = $this->carbon($row['wend']);
        }

        return [$start, $end];
    }

    /**
     * @return array{wstart: CarbonImmutable|null, wend: CarbonImmutable|null}
     */
    private function resolveWarrantyDates(array $row, CarbonImmutable $start): array
    {
        $wstart = $this->carbon($row['wstart']);
        $wend = $this->carbon($row['wend']);

        if (! $wstart || ! $wend) {
            if ($row['type'] === 'warranty') {
                return ['wstart' => $start, 'wend' => $start->addYears(5)];
            }

            return ['wstart' => null, 'wend' => null];
        }

        return ['wstart' => $wstart, 'wend' => $wend];
    }

    private function includesSpareParts(array $row): bool
    {
        if ($row['type'] === 'warranty') {
            return true;
        }

        $notes = (string) $row['notes'];
        if (str_contains($notes, 'بدون قطع غيار')) {
            return false;
        }

        return str_contains($notes, 'قطع غيار') || str_contains($notes, 'شامل القطع');
    }

    private function carbon(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value) : null;
    }

    private function show(array|string $message): void
    {
        if ($this->command) {
            $this->command->info(implode(PHP_EOL, (array) $message));
        }
    }
}
