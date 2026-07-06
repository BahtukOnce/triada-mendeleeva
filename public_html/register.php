<?php
// Отдельной регистрации больше нет: аккаунт рождается из принятой заявки на вступление
// (/join.php → приём руководителем → активация по ссылке). Старые ссылки ведём на заявку.
require dirname(__DIR__) . '/inc/bootstrap.php';

if (current_user()) {
    redirect('/cabinet.php');
}
redirect('/join.php');
