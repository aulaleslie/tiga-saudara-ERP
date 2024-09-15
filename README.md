<picture>
    <source srcset="public/images/logo.png"  
            media="(prefers-color-scheme: dark)">
    <img src="public/images/logo-dark.png" alt="App Logo">
</picture>

> **Important Note:** This Project is ready for Production. But use code from main branch only. If you find any bug or have any suggestion please create an Issue.

# Local Installation

- run `` git clone https://github.com/aulaleslie/tiga-saudara-ERP.git ``
- run ``composer install `` 
- run `` npm install ``
- run ``npm run dev``
- copy .env.example to .env
- run `` php artisan key:generate ``
- set up your database in the .env
- run `` php artisan migrate --seed ``
- run `` php artisan storage:link ``
- run `` php artisan serve ``
- then visit `` http://localhost:8000 or http://127.0.0.1:8000 ``.

# Admin Credentials
> Email: super.admin@test.com || Password: 12345678

## Demo
![Tiga Saudara ERP](public/images/screenshot.jpg)
**Live Demo:** will update soon

## Tiga Saudara ERP Features

- **Products Management & Barcode Printing**
- **Stock Management**
- **Make Quotation & Send Via Email**
- **Purchase Management**
- **Sale Management**
- **Purchase & Sale Return Management**
- **Expense Management**
- **Customer & Supplier Management**
- **Pengaturan Pengguna (Peran & Izin)**
- **Product Multiple Images**
- **Multiple Currency Settings**
- **Unit Settings**
- **System Settings**
- **Reports**

### PDF Configuration for Windows

> **Important Note:** "Tiga Saudara ERP" uses the Laravel Snappy package for PDFs. If you are using Linux, no further configuration is needed. For Windows or other operating systems, follow these steps:

1. **Download and Install `wkhtmltopdf`**:
    - Download `wkhtmltopdf` for Windows from [wkhtmltopdf.org](https://wkhtmltopdf.org/downloads.html).
    - Install the Windows version of `wkhtmltopdf` (typically installed in `C:\Program Files\wkhtmltopdf`).

2. **Find the Short Path for `wkhtmltopdf` on Windows**:
    - Open Command Prompt and run the following command to find the short path of the `Program Files` directory:
      ```bash
      dir /X "C:\Program Files"
      ```
    - The short name for `Program Files` is usually something like `C:\PROGRA~1`.
    - Next, get the short path for the `wkhtmltopdf\bin` folder:
      ```bash
      dir /X "C:\Program Files\wkhtmltopdf\bin"
      ```
    - The full short path will look something like: `C:\PROGRA~1\wkhtmltopdf\bin\WKHTML~2.EXE`.

3. **Update `.env` or `config/snappy.php`**:
    - Open the `.env` file and update the `WKHTML_PDF_BINARY` with the short path:
      ```bash
      WKHTML_PDF_BINARY="C:\\PROGRA~1\\wkhtmltopdf\\bin\\WKHTML~2.EXE"
      ```

4. **Clear Config Cache**:
   After updating the configuration, clear the Laravel configuration cache to ensure the changes take effect:
   ```bash
   php artisan config:clear
   
5. **Test PDF Generation**:
   After completing the above steps, you should be able to generate PDFs without any issues.

# License
**[Creative Commons Attribution 4.0	cc-by-4.0](https://creativecommons.org/licenses/by/4.0/)**
