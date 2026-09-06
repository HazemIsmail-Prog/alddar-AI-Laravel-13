<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('staff', fn ($user) => $user && $user->is_active);
