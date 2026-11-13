<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ProfileRepository extends Repository
{
    public function get(int $userId): array
    {
        $profile = $this->one('SELECT * FROM user_profiles WHERE user_id = ?', [$userId]) ?? [];
        $profile['employment'] = $this->rows(
            'SELECT * FROM employment_history WHERE user_id = ? ORDER BY sort_order, id',
            [$userId],
        );
        $profile['education'] = $this->rows(
            'SELECT * FROM education WHERE user_id = ? ORDER BY id',
            [$userId],
        );
        $profile['skills'] = array_column(
            $this->rows('SELECT name FROM skills WHERE user_id = ? ORDER BY id', [$userId]),
            'name',
        );
        $profile['preferences'] = json_decode($profile['preferences_json'] ?? '{}', true);
        $profile['details'] = json_decode($profile['details_json'] ?? '{}', true);
        unset($profile['preferences_json'], $profile['details_json']);
        return $profile;
    }

    public function save(int $userId, array $data): void
    {
        Database::transaction(function () use ($userId, $data): void {
            $this->lockUser($userId);
            $this->query(
                'UPDATE user_profiles SET full_name = ?, email = ?, phone = ?, location = ?, summary = ?, preferences_json = ?, details_json = ?, verified_at = ?, updated_at = NOW(), revision = revision + 1 WHERE user_id = ?',
                [
                    $data['full_name'],
                    $data['email'],
                    $data['phone'],
                    $data['location'],
                    $data['summary'],
                    json_encode($data['preferences'], JSON_THROW_ON_ERROR),
                    json_encode($data['details'], JSON_THROW_ON_ERROR),
                    $data['verified'] ? date('Y-m-d H:i:s') : null,
                    $userId,
                ],
            );
            $this->query('UPDATE users SET name = ? WHERE id = ?', [$data['full_name'], $userId]);
            foreach (['employment_history', 'education', 'skills'] as $table) {
                $this->query('DELETE FROM ' . $table . ' WHERE user_id = ?', [$userId]);
            }
            foreach ($data['employment'] as $index => $employment) {
                $this->query(
                    'INSERT INTO employment_history (user_id, position, employer, start_date, end_date, achievements, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $userId,
                        $employment['position'],
                        $employment['employer'],
                        $employment['start_date'],
                        $employment['end_date'],
                        $employment['achievements'],
                        $index,
                    ],
                );
            }
            foreach ($data['education'] as $education) {
                $this->query(
                    'INSERT INTO education (user_id, qualification, institution, completion_year) VALUES (?, ?, ?, ?)',
                    [
                        $userId,
                        $education['qualification'],
                        $education['institution'],
                        $education['completion_year'],
                    ],
                );
            }
            foreach (array_unique($data['skills']) as $skill) {
                $this->query('INSERT INTO skills (user_id, name) VALUES (?, ?)', [$userId, $skill]);
            }
            // A changed profile invalidates old analyses and document approvals.
            $this->query('DELETE FROM match_results WHERE user_id = ?', [$userId]);
            $this->query(
                "UPDATE applications SET documents_verified_at = NULL, approved_at = NULL, status = CASE WHEN status = 'approved' THEN 'ready_for_review' ELSE status END WHERE user_id = ? AND status IN ('draft', 'ready_for_review', 'approved')",
                [$userId],
            );
        });
    }
}
