<?php

pest()
    ->in('tests/Feature')
    ->in('tests/Unit')
    ->in('app/Api/*/Tests')
    ->in('app/Bots/Tests')
    ->in('app/Dav/Tests');
