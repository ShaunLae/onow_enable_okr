ONOW Enable OKR Management System
Student Name:  Shaun Lae Wai
Student ID:           001512449
Module:        COMP1682 - BSc (Hons) Computing Final Year Project


1. REQUIREMENTS
- XAMPP (Apache + MySQL + PHP 8)
- Browser: Chrome or Firefox recommended


2. SETUP STEPS
1. Copy the entire onow-enable folder into:
   Windows:  C:\xampp\htdocs\
   Mac:      /Applications/XAMPP/xamppfiles/htdocs/


2. Start Apache and MySQL in the XAMPP Control Panel.


3. Open phpMyAdmin:
   http://localhost/phpmyadmin


4. Create a new database named:
   onow_enable_okr


5. Import the SQL files IN THIS ORDER:
   a. database.sql       (main schema and seed data)
   b. migration.sql   (key results update and visibility feature)


6. Set passwords — visit this URL in your browser:
   http://localhost/onow_enable/setup_passwords.php
   You will see a confirmation message.
   All passwords are set to: Admin@1234


7. Open the system:
   http://localhost/onow_enable


3. LOGIN CREDENTIALS
Administrator:
  Email:    admin@onow-enable.org
  Password: Admin@1234


Manager:
  Email:    sarah.johnson@onow-enable.org
  Password: Admin@1234


Member 1:
  Email:    james.chen@onow-enable.org
  Password: Admin@1234


Member 2:
  Email:    aisha.mohammed@onow-enable.org
  Password: Admin@1234


NOTES
- The uploads/ folder permissions are set automatically by the system.
- Tested on XAMPP 8.2 on macOS and Windows 11.