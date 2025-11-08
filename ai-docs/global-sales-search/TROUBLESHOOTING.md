# Global Menu Troubleshooting Guide

## Common Issues and Solutions

### 1. No Search Results Found

**Symptoms:**
- Search returns empty results
- Autocomplete shows no suggestions

**Possible Causes:**
- Missing tenant context (`setting_id` not set in session)
- Incorrect permissions
- Database connection issues
- Invalid search criteria

**Solutions:**
1. Check tenant context:
   ```php
   dd(session('setting_id'));
   ```
2. Verify permissions:
   ```php
   auth()->user()->can('globalMenu.access');
   auth()->user()->can('sales.search.global');
   ```
3. Check database connectivity
4. Review search query logs in `storage/logs/laravel.log`

### 2. Slow Search Performance

**Symptoms:**
- Searches take >2 seconds
- High server load during searches

**Possible Causes:**
- Missing database indexes
- Large result sets without pagination
- Inefficient queries
- Server resource constraints

**Solutions:**
1. Check database indexes:
   ```sql
   SHOW INDEX FROM sales;
   SHOW INDEX FROM dispatch_details;
   SHOW INDEX FROM product_serial_numbers;
   ```
2. Enable query logging:
   ```php
   DB::enableQueryLog();
   // Run search
   dd(DB::getQueryLog());
   ```
3. Optimize pagination settings
4. Consider Redis caching for frequent queries

### 3. Autocomplete Not Working

**Symptoms:**
- No suggestions appear
- JavaScript errors in console

**Possible Causes:**
- JavaScript not loaded
- API endpoint unreachable
- CORS issues
- Minification errors

**Solutions:**
1. Check browser console for JavaScript errors
2. Verify API endpoint: `/api/global-menu/suggest`
3. Check network tab for failed requests
4. Ensure `global-menu-autocomplete.js` is included

### 4. Permission Denied Errors

**Symptoms:**
- 403 Forbidden responses
- Access denied messages

**Possible Causes:**
- Missing permissions
- User not assigned to correct role
- Permission cache issues

**Solutions:**
1. Check user permissions:
   ```php
   $user = auth()->user();
   $permissions = $user->getAllPermissions()->pluck('name');
   ```
2. Clear permission cache:
   ```bash
   php artisan cache:clear
   php artisan permission:cache-reset
   ```
3. Assign permissions via seeder or manually

### 5. JavaScript Errors

**Symptoms:**
- Components not reactive
- Console errors
- UI not updating

**Possible Causes:**
- Livewire JavaScript not loaded
- jQuery conflicts
- Missing dependencies

**Solutions:**
1. Check Livewire asset loading:
   ```blade
   @livewireStyles
   @livewireScripts
   ```
2. Verify jQuery loading order
3. Check for JavaScript conflicts
4. Use browser dev tools to debug

### 6. DataTables Not Loading

**Symptoms:**
- Table shows "Processing..." indefinitely
- No data displayed
- JavaScript errors

**Possible Causes:**
- AJAX endpoint issues
- DataTables configuration errors
- Missing CSRF tokens

**Solutions:**
1. Check AJAX endpoint: `/global-menu/search`
2. Verify DataTables initialization
3. Add CSRF token to headers:
   ```javascript
   $.ajaxSetup({
       headers: {
           'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
       }
   });
   ```

### 7. Modal Not Opening

**Symptoms:**
- Serial numbers modal doesn't appear
- JavaScript errors on button click

**Possible Causes:**
- Bootstrap modal not initialized
- Missing modal HTML
- JavaScript event binding issues

**Solutions:**
1. Check modal HTML exists in view
2. Verify Bootstrap JavaScript loaded
3. Check event binding:
   ```javascript
   $('#serialNumbersModal').modal('show');
   ```

### 8. Filters Not Applying

**Symptoms:**
- Filter changes don't affect results
- UI shows filters applied but results unchanged

**Possible Causes:**
- Livewire property updates not triggering
- Filter validation issues
- Search service errors

**Solutions:**
1. Check Livewire property updates:
   ```php
   public function updatedFiltersStatus() {
       $this->applyFilters();
   }
   ```
2. Debug filter values:
   ```php
   Log::info('Applied filters:', $this->filters);
   ```
3. Test search service independently

## Debug Tools

### Enable Debug Mode
```php
// config/app.php
'debug' => env('APP_DEBUG', true),
```

### Logging
```php
// Add to GlobalMenuController methods
Log::info('Debug info', [
    'user_id' => auth()->id(),
    'setting_id' => session('setting_id'),
    'request_data' => $request->all()
]);
```

### Database Query Debugging
```php
// In SerialNumberSearchService
$query = $this->buildQuery($filters);
Log::info('SQL Query:', [
    'sql' => $query->toSql(),
    'bindings' => $query->getBindings()
]);
```

### Performance Monitoring
```php
// Response time tracking
$startTime = microtime(true);
// ... code ...
$responseTime = round((microtime(true) - $startTime) * 1000, 2);
Log::info('Response time: ' . $responseTime . 'ms');
```

## Maintenance Tasks

### Regular Checks
1. **Database Indexes:**
   ```sql
   SELECT * FROM information_schema.statistics
   WHERE table_schema = DATABASE()
   AND table_name IN ('sales', 'dispatch_details', 'product_serial_numbers');
   ```

2. **Permission Sync:**
   ```bash
   php artisan db:seed --class=PermissionsTableSeeder
   ```

3. **Cache Clearing:**
   ```bash
   php artisan cache:clear
   php artisan view:clear
   php artisan config:clear
   ```

4. **Log Rotation:**
   ```bash
   # Check log sizes
   ls -lh storage/logs/
   # Archive old logs if needed
   ```

### Performance Optimization
1. **Query Optimization:**
   - Add composite indexes for common search patterns
   - Use query result caching
   - Implement database query monitoring

2. **Frontend Optimization:**
   - Minify JavaScript assets
   - Implement lazy loading for large datasets
   - Use CDN for static assets

3. **Server Optimization:**
   - Configure PHP OPcache
   - Set up database connection pooling
   - Implement Redis for session/cache storage

## Emergency Procedures

### Complete System Failure
1. Check server resources (CPU, memory, disk)
2. Verify database connectivity
3. Check Laravel logs for errors
4. Restart services if needed
5. Contact system administrator

### Data Corruption
1. **Don't panic** - don't make changes
2. Check database backups
3. Run integrity checks:
   ```sql
   CHECK TABLE sales, sale_details, dispatch_details;
   ```
4. Restore from backup if necessary
5. Document incident for post-mortem

### Security Incidents
1. Immediately disable affected user accounts
2. Change system passwords
3. Review access logs
4. Update security policies
5. Report to security team

## Support Contacts

- **Development Team:** [contact information]
- **System Administrator:** [contact information]
- **Database Administrator:** [contact information]
- **Security Team:** [contact information]

## Version Information

- **Laravel Version:** 10.x
- **Livewire Version:** 3.x
- **PHP Version:** 8.1+
- **MySQL Version:** 8.0+
- **Bootstrap Version:** 5.x</content>
<parameter name="filePath">/home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/ai-docs/global-menu/TROUBLESHOOTING.md