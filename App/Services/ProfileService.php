<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\ProfileRepository;
use App\Services\Security\Validator;

final class ProfileService
{
    public function save(int $userId, array $input): void
    {
        $profile = [];
        foreach (
            ['full_name' => 150, 'phone' => 60, 'location' => 200, 'summary' => 10000]
            as $field => $max
        ) {
            $profile[$field] = Validator::text(
                $input[$field] ?? '',
                $field,
                $max,
                $field === 'full_name',
            );
        }
        $profile['email'] = Validator::email($input['email'] ?? '', true);
        $profile['skills'] = Validator::lines($input['skills'] ?? '');
        $profile['employment'] = $this->records($input['employment'] ?? [], [
            'position' => 200,
            'employer' => 200,
            'start_date' => 20,
            'end_date' => 20,
            'achievements' => 5000,
        ]);
        $profile['education'] = $this->records($input['education'] ?? [], [
            'qualification' => 250,
            'institution' => 250,
            'completion_year' => 20,
        ]);
        $preferences = $input['preferences'] ?? [];
        if (!is_array($preferences)) {
            throw new HttpException('Invalid career preferences.');
        }
        $profile['preferences'] = [];
        foreach (['titles', 'industries', 'locations', 'employment_types'] as $field) {
            $profile['preferences'][$field] = Validator::lines($preferences[$field] ?? '');
        }
        $profile['preferences']['minimum_salary'] = Validator::number(
            $preferences['minimum_salary'] ?? 0,
            'minimum salary',
            0,
            999999999,
        );
        $profile['preferences']['workplace'] = Validator::choice(
            $preferences['workplace'] ?? 'any',
            'work arrangement',
            ['any', 'remote', 'hybrid', 'office'],
        );
        $details = $input['details'] ?? [];
        if (!is_array($details)) {
            throw new HttpException('Invalid profile details.');
        }
        $profile['details'] = [];
        foreach (
            ['certifications', 'languages', 'achievements', 'portfolio_links', 'references']
            as $field
        ) {
            $profile['details'][$field] = Validator::text($details[$field] ?? '', $field, 10000);
        }
        $profile['verified'] = !empty($input['verified']);
        (new ProfileRepository())->save($userId, $profile);
        (new ActivityRepository())->log(
            $userId,
            'profile.saved',
            'Updated the career profile' .
                ($profile['verified'] ? ' and verified its accuracy.' : '.'),
        );
    }

    private function records(mixed $rows, array $fields): array
    {
        if (!is_array($rows) || count($rows) > 50) {
            throw new HttpException('Supply at most 50 career records.');
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new HttpException('Invalid career record.');
            }
            if (trim((string) ($row[array_key_first($fields)] ?? '')) === '') {
                continue;
            }
            $record = [];
            foreach ($fields as $name => $length) {
                $record[$name] = Validator::text(
                    $row[$name] ?? '',
                    $name,
                    $length,
                    in_array($name, ['position', 'employer', 'qualification', 'institution'], true),
                );
            }
            $result[] = $record;
        }
        return $result;
    }
}
