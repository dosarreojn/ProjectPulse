<?php

declare(strict_types=1);

function teacher_management_bootstrap(): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    database()->exec(
        'CREATE TABLE IF NOT EXISTS teacher_section_assignments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            teacher_user_id INT UNSIGNED NOT NULL,
            section_id INT UNSIGNED NOT NULL,
            school_year_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY teacher_section_sy (teacher_user_id, section_id, school_year_id),
            UNIQUE KEY section_sy (section_id, school_year_id),
            FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
            FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $bootstrapped = true;
}

function teacher_form_defaults(): array
{
    return [
        'id' => null,
        'username' => '',
        'email' => '',
        'password' => '',
        'first_name' => '',
        'middle_name' => '',
        'last_name' => '',
        'section_id' => '',
    ];
}

function teacher_normalize_payload(array $payload): array
{
    return [
        'id' => !empty($payload['id']) ? (int) $payload['id'] : null,
        'username' => trim((string) ($payload['username'] ?? '')),
        'email' => trim((string) ($payload['email'] ?? '')),
        'password' => (string) ($payload['password'] ?? ''),
        'first_name' => trim((string) ($payload['first_name'] ?? '')),
        'middle_name' => trim((string) ($payload['middle_name'] ?? '')),
        'last_name' => trim((string) ($payload['last_name'] ?? '')),
        'section_id' => trim((string) ($payload['section_id'] ?? '')),
    ];
}

function teacher_save(array $form): void
{
    $currentSchoolYear = require_current_school_year();
    $pdo = database();
    $pdo->beginTransaction();

    try {
        if ($form['id'] === null) {
            $userId = auth_create_user(
                $form['username'],
                $form['email'],
                $form['password'],
                'teacher',
                $form['first_name'],
                $form['last_name']
            );
        } else {
            $userId = $form['id'];
            auth_update_user(
                $userId,
                $form['username'],
                $form['email'],
                $form['password'],
                'teacher',
                $form['first_name'],
                $form['last_name']
            );
        }

        $assignmentStatement = $pdo->prepare(
            'SELECT id FROM teacher_section_assignments WHERE teacher_user_id = :user_id AND school_year_id = :sy_id'
        );
        $assignmentStatement->execute(['user_id' => $userId, 'sy_id' => (int) $currentSchoolYear['id']]);
        $existingAssignmentId = $assignmentStatement->fetchColumn();

        if ($form['section_id'] !== '') {
            if ($existingAssignmentId !== false) {
                $updateAssignment = $pdo->prepare(
                    'UPDATE teacher_section_assignments SET section_id = :section_id WHERE id = :id'
                );
                $updateAssignment->execute(['section_id' => (int) $form['section_id'], 'id' => $existingAssignmentId]);
            } else {
                $insertAssignment = $pdo->prepare(
                    'INSERT INTO teacher_section_assignments (teacher_user_id, section_id, school_year_id) VALUES (:user_id, :section_id, :sy_id)'
                );
                $insertAssignment->execute([
                    'user_id' => $userId,
                    'section_id' => (int) $form['section_id'],
                    'sy_id' => (int) $currentSchoolYear['id'],
                ]);
            }
        } elseif ($existingAssignmentId !== false) {
            $deleteAssignment = $pdo->prepare('DELETE FROM teacher_section_assignments WHERE id = :id');
            $deleteAssignment->execute(['id' => $existingAssignmentId]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function teacher_delete(int $teacherId): void
{
    auth_delete_user($teacherId);
}

function teacher_find(int $teacherId): ?array
{
    $currentSchoolYear = require_current_school_year();
    $statement = database()->prepare(
        'SELECT
            u.id,
            u.username,
            u.email,
            u.first_name,
            u.middle_name,
            u.last_name,
            tsa.section_id
         FROM users u
         LEFT JOIN teacher_section_assignments tsa
            ON tsa.teacher_user_id = u.id
           AND tsa.school_year_id = :sy_id
         WHERE u.id = :id AND u.role = \'teacher\''
    );
    $statement->execute(['id' => $teacherId, 'sy_id' => (int) $currentSchoolYear['id']]);
    $row = $statement->fetch();

    return $row === false ? null : teacher_normalize_payload($row);
}

function teacher_list(): array
{
    $currentSchoolYear = require_current_school_year();
    $statement = database()->prepare(
        'SELECT
            u.id,
            u.username,
            u.email,
            u.first_name,
            u.middle_name,
            u.last_name,
            s.grade_level,
            s.name AS section_name,
            sy.label AS school_year_label
         FROM users u
         LEFT JOIN teacher_section_assignments tsa
            ON tsa.teacher_user_id = u.id
           AND tsa.school_year_id = :sy_id
         LEFT JOIN sections s ON s.id = tsa.section_id
         LEFT JOIN school_years sy ON sy.id = tsa.school_year_id
         WHERE u.role = \'teacher\'
         ORDER BY u.last_name, u.first_name'
    );
    $statement->execute(['sy_id' => (int) $currentSchoolYear['id']]);

    return $statement->fetchAll();
}

function teacher_section_options(): array
{
    $currentSchoolYear = require_current_school_year();
    $statement = database()->prepare(
        'SELECT
            s.id,
            s.grade_level,
            s.name,
            sy.label AS school_year_label,
            u.username AS assigned_teacher_username,
            CONCAT(u.first_name, \' \', u.last_name) AS assigned_teacher_name
         FROM sections s
         INNER JOIN school_years sy ON sy.id = s.school_year_id
         LEFT JOIN teacher_section_assignments tsa ON tsa.section_id = s.id
         LEFT JOIN users u ON u.id = tsa.teacher_user_id
         WHERE s.school_year_id = :sy_id
         ORDER BY s.grade_level, s.name'
    );
    $statement->execute(['sy_id' => (int) $currentSchoolYear['id']]);

    return $statement->fetchAll();
}

function teacher_assigned_section(int $teacherUserId): ?array
{
    $currentSchoolYear = require_current_school_year();
    $statement = database()->prepare(
        'SELECT
            s.id,
            s.name,
            s.grade_level,
            sy.label AS school_year_label,
            sy.start_date AS school_year_start_date,
            sy.id as school_year_id
         FROM teacher_section_assignments tsa
         INNER JOIN sections s ON s.id = tsa.section_id
         INNER JOIN school_years sy ON sy.id = tsa.school_year_id
         WHERE tsa.teacher_user_id = :teacher_user_id
           AND tsa.school_year_id = :sy_id
         LIMIT 1'
    );
    $statement->execute(['teacher_user_id' => $teacherUserId, 'sy_id' => (int) $currentSchoolYear['id']]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function teacher_section_learners(int $teacherUserId): array
{
    $section = teacher_assigned_section($teacherUserId);

    if ($section === null) {
        return [];
    }

    return learner_list(['section_id' => (string) $section['id']]);
}

function teacher_accessible_learner(int $teacherUserId, int $learnerId): ?array
{
    $section = teacher_assigned_section($teacherUserId);

    if ($section === null) {
        return null;
    }

    $statement = database()->prepare(
        'SELECT l.*
         FROM learners l
         INNER JOIN learner_enrollments le ON le.learner_id = l.id
         WHERE le.section_id = :section_id AND l.id = :learner_id
         LIMIT 1'
    );
    $statement->execute(['section_id' => (int) $section['id'], 'learner_id' => $learnerId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function teacher_manual_attendance_save(int $teacherUserId, int $learnerId, string $attendanceDate, string $attendanceCode, string $remarks): void
{
    $learner = teacher_accessible_learner($teacherUserId, $learnerId);
    if ($learner === null) {
        throw new RuntimeException('Selected learner is not in your section.');
    }

    $enrollment = learner_enrollment_for_current_school_year((int) $learner['id']);
    if ($enrollment === null) {
        throw new RuntimeException('Learner is not enrolled in the current school year.');
    }

    manual_attendance_save(
        (int) $enrollment['id'],
        $attendanceDate,
        $attendanceCode,
        $remarks,
        'Manually recorded by teacher'
    );
}

function teacher_historical_school_years(int $teacherUserId): array
{
    $statement = database()->prepare(
        'SELECT DISTINCT sy.id, sy.label
         FROM teacher_section_assignments tsa
         INNER JOIN school_years sy ON sy.id = tsa.school_year_id
         WHERE tsa.teacher_user_id = :teacher_user_id
         ORDER BY sy.start_date DESC'
    );
    $statement->execute(['teacher_user_id' => $teacherUserId]);

    return $statement->fetchAll();
}