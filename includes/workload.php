<?php
/**
 * F02 — Helpers puros para o forecast semanal por contagem de tasks.
 *
 * Sem DB, sem HTTP. Todo o input é passado por argumento (arrays de tasks com
 * os campos `id`, `parent_id`, `status_name`, `start_date`, `due_date`). Datas
 * ClickUp são epoch em milissegundos.
 *
 * Referência: `specs/10-active/F02-plano-carga-semanal/spec.md § Decisões pós-spike`.
 */

declare(strict_types=1);

const FORECAST_UNDER_RATIO = 0.80;
const FORECAST_OVER_RATIO  = 1.10;

/**
 * Status que não entram no forecast. Comparação case-insensitive.
 * Flipping "scheduled" ou "ready to post" para fora da lista é mudança de 1 linha
 * se a equipa reportar que ainda têm trabalho de design activo.
 */
const FORECAST_TERMINAL_STATUSES = [
    'published',
    'mês social terminado',
    'post cancelado',
    'linha editorial cancelada',
    'scheduled',
    'ready to post',
];

/**
 * É um status terminal? Case-insensitive, trim, unicode-safe (mb_strtolower).
 */
function forecast_is_terminal(?string $status_name): bool
{
    if ($status_name === null) {
        return false;
    }
    $needle = mb_strtolower(trim($status_name), 'UTF-8');
    if ($needle === '') {
        return false;
    }
    foreach (FORECAST_TERMINAL_STATUSES as $terminal) {
        if (mb_strtolower($terminal, 'UTF-8') === $needle) {
            return true;
        }
    }
    return false;
}

/** Retorna true se a data cai num dia útil (seg-sex). */
function forecast_is_weekday(DateTimeImmutable $d): bool
{
    $n = (int) $d->format('N'); // 1..7 (Mon..Sun)
    return $n >= 1 && $n <= 5;
}

/**
 * Devolve os 5 dias úteis (seg-sex) da semana corrente, 00:00 no tz indicado.
 * Se `$now` for null, usa "now" no tz.
 */
function forecast_current_week(DateTimeZone $tz, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now', $tz);
    $now = $now->setTimezone($tz)->setTime(0, 0, 0);

    $dayOfWeek = (int) $now->format('N'); // 1 (Mon) .. 7 (Sun)
    $monday    = $now->modify('-' . ($dayOfWeek - 1) . ' days');

    $days = [];
    for ($i = 0; $i < 5; $i++) {
        $days[] = $monday->modify('+' . $i . ' days');
    }
    return $days;
}

/**
 * Converte epoch-ms (int|string|null) para DateTimeImmutable no tz indicado,
 * truncado a 00:00. Devolve null se ms for null.
 */
function forecast_ms_to_day(int|string|null $ms, DateTimeZone $tz): ?DateTimeImmutable
{
    if ($ms === null || $ms === '') {
        return null;
    }
    $seconds = intdiv((int) $ms, 1000);
    return (new DateTimeImmutable('@' . $seconds))
        ->setTimezone($tz)
        ->setTime(0, 0, 0);
}

/**
 * Dias úteis (YYYY-MM-DD) em que esta task conta. Vazio se não deve aparecer.
 *
 * Regras (modelo C, contagem):
 *  - Status terminal → []
 *  - Overdue (due < now, não terminal) → [hoje]
 *  - start + due     → dias úteis entre os dois (inclusive)
 *  - só due          → 1 dia útil imediatamente antes do due
 *  - só start        → start (se for dia útil)
 *  - ambos null      → []
 */
function forecast_task_days(array $task, DateTimeImmutable $now, DateTimeZone $tz): array
{
    if (forecast_is_terminal($task['status_name'] ?? null)) {
        return [];
    }

    $startMs = $task['start_date'] ?? null;
    $dueMs   = $task['due_date']   ?? null;

    if ($startMs === null && $dueMs === null) {
        return [];
    }

    $nowDay = $now->setTimezone($tz)->setTime(0, 0, 0);

    // Overdue → hoje (se hoje for dia útil; senão, próximo dia útil para trás,
    // mas na prática "hoje não útil" no forecast é sáb/dom e cai fora da janela).
    if ($dueMs !== null) {
        $dueDay = forecast_ms_to_day($dueMs, $tz);
        if ($dueDay !== null && $dueDay < $nowDay) {
            $today = $nowDay;
            while (!forecast_is_weekday($today)) {
                $today = $today->modify('-1 day');
            }
            return [$today->format('Y-m-d')];
        }
    }

    // Só due → 1 dia útil antes.
    if ($startMs === null && $dueMs !== null) {
        $dueDay = forecast_ms_to_day($dueMs, $tz);
        if ($dueDay === null) {
            return [];
        }
        $d = $dueDay->modify('-1 day');
        while (!forecast_is_weekday($d)) {
            $d = $d->modify('-1 day');
        }
        return [$d->format('Y-m-d')];
    }

    // Só start → start (se for dia útil).
    if ($startMs !== null && $dueMs === null) {
        $startDay = forecast_ms_to_day($startMs, $tz);
        if ($startDay === null || !forecast_is_weekday($startDay)) {
            return [];
        }
        return [$startDay->format('Y-m-d')];
    }

    // Ambos → enumera dias úteis entre start e due inclusive.
    $startDay = forecast_ms_to_day($startMs, $tz);
    $dueDay   = forecast_ms_to_day($dueMs,   $tz);
    if ($startDay === null || $dueDay === null) {
        return [];
    }
    if ($startDay > $dueDay) {
        // input inconsistente — trata como só due
        $d = $dueDay;
        while (!forecast_is_weekday($d)) {
            $d = $d->modify('-1 day');
        }
        return [$d->format('Y-m-d')];
    }
    $out = [];
    $cursor = $startDay;
    while ($cursor <= $dueDay) {
        if (forecast_is_weekday($cursor)) {
            $out[] = $cursor->format('Y-m-d');
        }
        $cursor = $cursor->modify('+1 day');
    }
    return $out;
}

/**
 * Classifica uma contagem vs alvo em `under` / `ok` / `over`.
 * Alvo 0 é degenerado: qualquer task → over; nenhuma task → under.
 */
function forecast_status(int $count, int $target): string
{
    if ($target <= 0) {
        return $count > 0 ? 'over' : 'under';
    }
    $ratio = $count / $target;
    if ($ratio < FORECAST_UNDER_RATIO) {
        return 'under';
    }
    if ($ratio > FORECAST_OVER_RATIO) {
        return 'over';
    }
    return 'ok';
}

/**
 * Agrega as tasks de um colaborador em buckets diários (seg-sex).
 *
 * Regra D5: se uma task tem um `id` que aparece como `parent_id` de outra task
 * no mesmo input, a parent é skipada — só as folhas contam.
 *
 * Tasks sem nenhuma data (`start_date` e `due_date` ambos null) não entram
 * em `active_count`; em vez disso incrementam `undated_count` na coluna de
 * hoje (se hoje estiver dentro da janela). Dá visibilidade sem inflacionar
 * contagens.
 *
 * Output: array de N buckets (um por weekday em `$weekdays`), cada um com
 * `{date, weekday, active_count, target, overdue_count, undated_count, status}`.
 */
function forecast_aggregate(
    array $tasks,
    int $daily_target,
    array $weekdays,
    DateTimeImmutable $now,
    DateTimeZone $tz
): array {
    // D5 — conjunto de ids que são parent de alguém no input.
    $parentIds = [];
    foreach ($tasks as $t) {
        $pid = $t['parent_id'] ?? null;
        if ($pid !== null && $pid !== '') {
            $parentIds[(string) $pid] = true;
        }
    }

    // Bucket por dia.
    $byDate = [];
    foreach ($weekdays as $wd) {
        $key = $wd->format('Y-m-d');
        $byDate[$key] = [
            'date'          => $key,
            'weekday'       => strtolower($wd->format('D')), // mon, tue, ...
            'active_count'  => 0,
            'target'        => $daily_target,
            'overdue_count' => 0,
            'undated_count' => 0,
            'status'        => 'under',
        ];
    }

    $nowDay  = $now->setTimezone($tz)->setTime(0, 0, 0);
    $todayKey = $nowDay->format('Y-m-d');

    foreach ($tasks as $t) {
        $id = (string) ($t['id'] ?? '');

        // D5 — parent com filhos no input é skipada.
        if ($id !== '' && isset($parentIds[$id])) {
            continue;
        }

        if (forecast_is_terminal($t['status_name'] ?? null)) {
            continue;
        }

        $startMs = $t['start_date'] ?? null;
        $dueMs   = $t['due_date']   ?? null;

        // Undated → incrementa coluna de hoje (se estiver na janela).
        if ($startMs === null && $dueMs === null) {
            if (isset($byDate[$todayKey])) {
                $byDate[$todayKey]['undated_count']++;
            }
            continue;
        }

        $overdue = false;
        if ($dueMs !== null) {
            $dueDay = forecast_ms_to_day($dueMs, $tz);
            if ($dueDay !== null && $dueDay < $nowDay) {
                $overdue = true;
            }
        }

        $days = forecast_task_days($t, $now, $tz);
        foreach ($days as $dateKey) {
            if (isset($byDate[$dateKey])) {
                $byDate[$dateKey]['active_count']++;
                if ($overdue) {
                    $byDate[$dateKey]['overdue_count']++;
                }
            }
        }
    }

    // Aplica status final.
    $out = [];
    foreach ($weekdays as $wd) {
        $key = $wd->format('Y-m-d');
        $b = $byDate[$key];
        $b['status'] = forecast_status((int) $b['active_count'], (int) $daily_target);
        $out[] = $b;
    }
    return $out;
}
