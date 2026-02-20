<?php require __DIR__ . '/../config/database.php'; $d=new Database(); $d->getConnection()->exec(file_get_contents('../database/2_update_enum_status.sql')); echo 'Enum atualizado';
