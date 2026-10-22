<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('office', fn (User $user) => true);
