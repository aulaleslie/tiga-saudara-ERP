<?php

/**
 * Permissions Configuration
 *
 * This file loads the centralized permission definitions from app/Config/Permissions.php
 * and makes them available throughout the application via config('permissions').
 */

return require app_path('Config/Permissions.php');
