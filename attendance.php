<?php

declare(strict_types=1);

function attendance_bootstrap(): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    $pdo = database();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS attendance_legends (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(10) NOT NULL UNIQUE,
            label VARCHAR(50) NOT NULL,
            counts_as_present TINYINT(1) NOT NULL DEFAULT 0,
            is_manual_only TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS attendance_records (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            learner_enrollment_id INT UNSIGNED NOT NULL,
            attendance_date DATE NOT NULL,
            legend_id INT UNSIGNED NULL,
            am_time_in TIME NULL,
            am_time_out TIME NULL,
            pm_time_in TIME NULL,
            pm_time_out TIME NULL,
            remarks VARCHAR(255) NULL,
            source VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_enrollment_date (learner_enrollment_id, attendance_date),
            CONSTRAINT fk_attendance_enrollment
                FOREIGN KEY (learner_enrollment_id) REFERENCES learner_enrollments(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_attendance_legend
                FOREIGN KEY (legend_id) REFERENCES attendance_legends(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS attendance_scan_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attendance_record_id INT UNSIGNED NULL,
            learner_enrollment_id INT UNSIGNED NOT NULL,
            legend_id INT UNSIGNED NULL,
            slot_key VARCHAR(20) NOT NULL,
            slot_label VARCHAR(50) NOT NULL,
            scanned_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_attendance_log_record
                FOREIGN KEY (attendance_record_id) REFERENCES attendance_records(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_attendance_log_enrollment
                FOREIGN KEY (learner_enrollment_id) REFERENCES learner_enrollments(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_attendance_log_legend
                FOREIGN KEY (legend_id) REFERENCES attendance_legends(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $bootstrapped = true;
}

function manual_attendance_save(int $learnerEnrollmentId, string $attendanceDate, string $attendanceCode, string $remarks, string $source): void
{
    attendance_bootstrap();
    $pdo = database();

    $legendStmt = $pdo->prepare('SELECT id FROM attendance_legends WHERE code = :code LIMIT 1');
    $legendStmt->execute(['code' => $attendanceCode]);
    $legend = $legendStmt->fetch();

    if ($legend === false) {
        throw new RuntimeException('Invalid attendance status code.');
    }

    $amTimeIn = null;
    $amTimeOut = null;
    $pmTimeIn = null;
    $pmTimeOut = null;

    if ($attendanceCode === 'P') {
        $amTimeIn = '00:00:00';
        $amTimeOut = '00:00:00';
        $pmTimeIn = '00:00:00';
        $pmTimeOut = '00:00:00';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO attendance_records (learner_enrollment_id, attendance_date, legend_id, remarks, source, am_time_in, am_time_out, pm_time_in, pm_time_out)
         VALUES (:enroll_id, :date, :legend_id, :remarks, :source, :am_in, :am_out, :pm_in, :pm_out)
         ON DUPLICATE KEY UPDATE
            legend_id = VALUES(legend_id),
            remarks = VALUES(remarks),
            source = VALUES(source),
            am_time_in = VALUES(am_time_in),
            am_time_out = VALUES(am_time_out),
            pm_time_in = VALUES(pm_time_in),
            pm_time_out = VALUES(pm_time_out),
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'enroll_id' => $learnerEnrollmentId,
        'date' => $attendanceDate,
        'legend_id' => $legend['id'],
        'remarks' => $remarks !== '' ? $remarks : null,
        'source' => $source,
        'am_in' => $amTimeIn,
        'am_out' => $amTimeOut,
        'pm_in' => $pmTimeIn,
        'pm_out' => $pmTimeOut,
    ]);
}