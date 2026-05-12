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
