Handouts storage for Johnnyfingers

This folder stores uploaded handouts and schematics uploaded via the admin panel.

Security & permissions (recommended):

- On Windows (IIS): ensure the IIS user (usually `IIS_IUSRS`) or the account running PHP has Modify permissions. Example (run as Administrator):

  icacls "%SYSTEMDRIVE%\xampp\htdocs\johnyfinger\assets\handouts" /grant "IIS_IUSRS:(OI)(CI)M" /T

- On Linux (Apache/Nginx): ensure the web server user (www-data, apache, or wwwrun) owns the folder:

  sudo chown -R www-data:www-data /var/www/path/to/johnyfinger/assets/handouts
  sudo chmod -R 755 /var/www/path/to/johnyfinger/assets/handouts

Best practices:
- Do not store executable scripts in this directory.
- If you serve files directly, consider restricting directory listing via web server config or add an `.htaccess` denying directory indexes.
- Monitor file sizes and quotas; current upload limit in code is 10 MB per file.

If you want, I can create a test upload from the admin UI or add an endpoint to verify uploads end-to-end.