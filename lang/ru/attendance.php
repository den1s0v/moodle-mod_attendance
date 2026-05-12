<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for component 'attendance', language 'ru'
 *
 * @package   mod_attendance
 * @copyright  2011 Artem Andreev <andreev.artem@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['enablelimitsessionspergroup'] = 'Разрешить ограничение занятий по группе';
$string['enablelimitsessionspergroup_desc'] = 'Если включено, позволяет устанавливать ограничение на количество занятий для каждой группы на уровне активности.';
$string['limitsessionspergroup'] = 'Ограничение занятий по группе';
$string['limitsessionspergroup_help'] = 'Установите максимальное количество занятий, которые могут быть созданы для каждой группы. Выберите "Без ограничения" для неограниченного количества занятий или "Максимум одно занятие у группы" для ограничения каждой группы одним занятием.';
$string['nolimit'] = 'Без ограничения';
$string['maxonesessionpergroup'] = 'Максимум одно занятие у группы';
$string['hassessions'] = 'есть занятия';
$string['groupswithsessionsinfo'] = 'Группы с существующими занятиями: {$a}';
$string['groupsessionslabel'] = 'Занятия по группам:';
$string['nosessions'] = 'В этой посещаемости нет занятий';
$string['createdbyattendance'] = 'Преподаватель: {$a}';
$string['showcreatorintooltip'] = 'Показывать создателя во всплывающей подсказке на странице курса';
$string['showcreatorintooltip_desc'] = 'Если включено, создатель экземпляра посещаемости отображается один раз в заголовке всплывающего окна на странице курса.';
$string['showsessioncreatorintooltip'] = 'Показывать создателя занятия во всплывающей подсказке';
$string['showsessioncreatorintooltip_desc'] = 'Если включено, для каждой строки занятия во всплывающем окне на странице курса отображается преподаватель, создавший это занятие.';
$string['enablestudentgroupfilterintooltip'] = 'Фильтровать занятия во всплывающей подсказке для студентов в режиме изолированных групп';
$string['enablestudentgroupfilterintooltip_desc'] = 'Если включено, в режиме изолированных групп студенты видят только занятия своих групп. В режимах "Видимые группы" и "Нет групп" показываются все группы. Если выключено, для всех пользователей показывается одно и то же общее кэшированное содержимое подсказки.';
$string['limitsessionspergroupexceeded'] = 'Невозможно создать занятия: следующие группы уже имеют максимально допустимое количество занятий: {$a}';
$string['recalculategrades'] = 'Пересчитать оценки посещаемости';
$string['recalculategradesconfirm'] = 'Запустить пересчёт';
$string['recalculategradesconfirmtext'] = 'Будет выполнен пересчёт оценок посещаемости для потенциально затронутых пользователей. Продолжить?';
$string['recalculategradesdone'] = 'Пересчёт завершён. Обновлено активностей посещаемости: {$a->activities}, оценок пользователей: {$a->users}.';
$string['recalculategradesnothing'] = 'Потенциально затронутые пользователи не найдены.';
$string['recalculategradessummary'] = 'Потенциально затронуто: активностей посещаемости {$a->activities}, пользователей {$a->users}.';
$string['recalculategradessummarydetailed'] = 'Потенциально затронуто: активностей посещаемости {$a->activities}, пользователей {$a->users}. Фильтр периода: {$a->era}. Дата исправления логики: {$a->fixdate}.';
$string['recalculategradespreview'] = 'Предпросмотр кандидатов';
$string['recalculategradesattendanceid'] = 'Фильтр по ID посещаемости';
$string['recalculategradesera'] = 'Фильтр периода';
$string['recalculategradesera_before'] = 'До даты исправления логики';
$string['recalculategradesera_after'] = 'С даты исправления логики и позже';
$string['recalculategradesera_all'] = 'Все';
$string['recalculategradesfixdate'] = 'Дата исправления логики';
$string['recalculategradesepsilon'] = 'Допуск расхождения (epsilon)';
$string['recalculategradesmode'] = 'Режим отбора кандидатов';
$string['recalculategradesmode_strict'] = 'Строгий — только строки журнала, где в SQL виден конфликт тайм-слота (параллельные занятия с разными баллами) И raw не совпадает с политикой summary';
$string['recalculategradesmode_fallback'] = 'Запасной — любое расхождение raw с политикой summary (без требования конфликта; предпросмотр обязателен)';
$string['recalculategradesmode_seeded'] = 'Явный список — по одной паре «ID посещаемости, ID пользователя» в строке (разделитель: запятая, таб или точка с запятой)';
$string['recalculategradesallinstances'] = 'Все экземпляры';
$string['recalculategradessummarydetailed2'] = 'Охват: активностей посещаемости {$a->activities}, пользователей {$a->users}. Период: {$a->era}. Дата исправления: {$a->fixdate}. Режим: {$a->mode}. Epsilon: {$a->eps}. Фильтр посещаемости: {$a->attendanceid}.';
$string['recalculategradesseedhelp'] = 'Для режима явного списка: строки вида «123,456» (id экземпляра посещаемости, id пользователя). В других режимах поле не используется.';
$string['recalculategradesseedempty'] = 'Выбран режим явного списка, но список пуст или не содержит допустимых строк.';
$string['recalculategradesdiag_title'] = 'Диагностика (почему список кандидатов пуст):';
$string['recalculategradesdiag_eligible'] = 'Подходящие строки журнала оценок (элемент attendance, raw задан, не переопределено/не исключено/не заблокировано, фильтр периода): {$a}';
$string['recalculategradesdiag_withpolicy'] = '…из них с хотя бы одним отмеченным занятием в SQL-агрегате политики: {$a}';
$string['recalculategradesdiag_sqlmismatch'] = '…из них с расхождением raw и SQL-политики (epsilon): {$a}';
$string['recalculategradesdiag_sqlmismatch_conflict'] = '…из них с флагом конфликта тайм-слота (строгий режим): {$a}';
$string['recalculategradesdiag_sqlmismatch_noconflict'] = '…расхождение SQL без флага конфликта (попробуйте запасной режим): {$a}';
$string['recalculategradesdiag_summaryhint'] = 'Итоговый список кандидатов фильтруется через mod_attendance_summary (как при живом пересчёте), включая дату начала курса для занятий.';
$string['recalculategradesrawbefore'] = 'Raw до';
$string['recalculategradesexpectedsql'] = 'Ожидаемое (SQL)';
$string['recalculategradesexpectedsummary'] = 'Ожидаемое (summary / apply)';
$string['recalculategradesdelta'] = 'Дельта';
$string['recalculategradeslastwrite'] = 'Последняя запись';
$string['recalculategradesconflict'] = 'Конфликт слота';
$string['recalculategradesrawafter'] = 'Raw после';
$string['recalculategradesfinalafter'] = 'Итог после';
$string['recalculategradesexpectedhelp'] = '«Ожидаемое (summary / apply)» — то, что запишет attendance_update_users_grades_by_id. «Ожидаемое (SQL)» — аналитический запрос; обычно совпадает, если данные не менялись.';
$string['recalculategradescmid'] = 'ID элемента курса (из URL)';
$string['recalculategradescmid_help'] = 'Необязательно. Укажите id из /mod/attendance/manage.php?id=… — он подставит экземпляр посещаемости вместо поля «ID посещаемости».';
$string['recalculategradescmidconflict'] = 'Заданы и ID посещаемости ({$a->fromfield}), и cmid ({$a->cmid}) — они не совпадают. Используется экземпляр {$a->fromcm} из cmid.';
$string['recalculategradesforensic_title'] = 'Подробный разбор по одному пользователю';
$string['recalculategradesforensic_needscope'] = 'Чтобы показать пошаговый разбор, укажите область: cmid из URL или ID экземпляра посещаемости.';
$string['recalculategradesforensic_noattendance'] = 'Экземпляр посещаемости не найден.';
$string['recalculategradesforensic_nouser'] = 'Пользователь не найден.';
$string['recalculategradesforensic_step_noitem'] = 'В журнале оценок нет элемента для этой активности.';
$string['recalculategradesforensic_step_nograde'] = 'Нет строки grade_grades для этого пользователя и элемента.';
$string['recalculategradesforensic_step_flags'] = 'Флаги строки оценки мешают автопересчёту: {$a}';
$string['recalculategradesforensic_step_flagsok'] = 'Строка не переопределена / не исключена / не заблокирована.';
$string['recalculategradesforensic_step_grades'] = 'Текущие значения: raw={$a->raw}, final={$a->final}, grademax элемента={$a->max}.';
$string['recalculategradesforensic_step_lastwrite'] = 'Последняя запись в истории оценок (item+user): {$a}.';
$string['recalculategradesforensic_step_erapass_before'] = 'Фильтр периода «до даты»: пройден (нет истории или запись до даты исправления).';
$string['recalculategradesforensic_step_erafail_before'] = 'Фильтр периода «до даты»: не пройден (последняя запись после даты — строка не попадёт в before_fix).';
$string['recalculategradesforensic_step_erapass_after'] = 'Фильтр периода «после»: пройден.';
$string['recalculategradesforensic_step_erafail_after'] = 'Фильтр периода «после»: не пройден.';
$string['recalculategradesforensic_step_eraall'] = 'Фильтр периода: все (без отсечения по дате записи).';
$string['recalculategradesforensic_step_coursestart'] = 'Дата начала курса (как в summary SQL): {$a}.';
$string['recalculategradesforensic_step_nopt'] = 'Нет агрегата политики из attendance_log (нет подходящих отмеченных занятий или maxpoints=0).';
$string['recalculategradesforensic_step_pt'] = 'SQL-агрегат: баллы={$a->points}, max={$a->maxp}, конфликт слота={$a->conflict}, ожидаемый raw (если известен grademax)={$a->sqlexp}.';
$string['recalculategradesforensic_step_noactivitygrade'] = 'В активности не задана оценка (0) — summary-raw для apply не считается.';
$string['recalculategradesforensic_step_summary'] = 'Summary (как при apply): % занятий = {$a->pct}%, ожидаемый raw = {$a->exp}.';
$string['recalculategradesforensic_step_summarynone'] = 'Summary: нет отмеченных занятий (при полном пересчёте raw может обнулиться).';
$string['recalculategradesforensic_step_sqlmatch'] = 'SQL-ожидание совпадает с текущим raw (в пределах epsilon).';
$string['recalculategradesforensic_step_sqlmismatch'] = 'SQL-ожидание отличается от текущего raw на {$a} (raw − SQL).';
$string['recalculategradesforensic_step_summarymatch'] = 'Summary-ожидание совпадает с текущим raw (в пределах epsilon).';
$string['recalculategradesforensic_step_summarymismatch'] = 'Summary-ожидание отличается от текущего raw на {$a} (raw − summary).';
$string['recalculategradesforensic_hist_title'] = 'Последние записи grade_grades_history (до 25)';
$string['recalculategradesforensic_hist_time'] = 'Время';
$string['recalculategradesforensic_hist_user'] = 'Кто менял (id)';
$string['recalculategradesforensic_hist_raw'] = 'Raw';
$string['recalculategradesforensic_hist_final'] = 'Final';
$string['recalculategradesforensic_hist_empty'] = 'Нет истории для этой пары item+user.';
$string['recalculategradesforensic_userid'] = 'ID пользователя (для пошагового разбора)';
$string['recalculategradessummarydetailed3'] = 'Охват: активностей {$a->activities}, пользователей {$a->users}. Период: {$a->era}. Дата: {$a->fixdate}. Режим: {$a->mode}. Epsilon: {$a->eps}. Поле ID посещаемости: {$a->attendanceid}. CMID из URL: {$a->cmid}. Экземпляр в запросах: {$a->instance}. Отсекать понижение: {$a->rejectlower}.';
$string['recalculategrades_reason_title'] = 'Разбор по причинам (счётчики)';
$string['recalculategrades_reason_pool'] = 'Строк после SQL-отбора (правила режима): {$a}';
$string['recalculategrades_reason_droppedsummary'] = 'Отброшено: summary совпадает с текущим raw (в epsilon): {$a}';
$string['recalculategrades_reason_mismatchsummary'] = 'Строк с расхождением summary (до фильтра «не понижать»): {$a}';
$string['recalculategrades_reason_raise'] = '…из них apply поднял бы raw: {$a}';
$string['recalculategrades_reason_lower'] = '…из них apply понизил бы raw: {$a}';
$string['recalculategrades_reason_edge'] = '…из них пограничные/null: {$a}';
$string['recalculategrades_reason_excludedlower'] = 'Исключено из списка apply опцией «не понижать»: {$a}';
$string['recalculategrades_reason_applynote'] = 'Кнопка «Пересчитать» обновляет только строки, оставшиеся в таблице ниже.';
$string['recalculategrades_rejectlower'] = 'Не понижать raw при apply';
$string['recalculategrades_rejectlower_yes'] = 'Да — исключать строки, где ожидаемый raw ниже текущего';
$string['recalculategrades_rejectlower_no'] = 'Нет — все расхождения summary (осторожно)';
$string['recalculategrades_col_currentraw'] = 'Текущий raw (сейчас в журнале)';
$string['recalculategrades_col_sqldiag'] = 'Ожидаемый raw (SQL, диагностика)';
$string['recalculategrades_col_expectedapply'] = 'Ожидаемый raw после пересчёта (summary/apply)';
$string['recalculategrades_col_potentialrawdelta'] = 'Потенциальное изменение raw (ожидаемый − текущий)';
$string['recalculategrades_col_lastwrite'] = 'Последняя запись в истории';
$string['recalculategrades_col_conflict'] = 'Конфликт слота (SQL)';
$string['recalculategrades_col_rawafter'] = 'Raw после apply';
$string['recalculategrades_col_finalafter'] = 'Итог после apply';
$string['recalculategrades_tablehelp'] = '«Ожидаемый raw после пересчёта» — то, что запишет модуль тем же summary, что и в работе. «SQL диагностика» — отдельная проверка; при расхождении выполните пошаговый разбор для этого пользователя (поле ID пользователя выше).';
