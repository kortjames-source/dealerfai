Hostinger phpMyAdmin Restore Checklist

1) Create the database and user in Hostinger (if not already done).
   - Give the user ALL privileges on that database.

2) Open phpMyAdmin.

3) Select the database in the left sidebar.

4) Go to the Import tab.

5) Choose file:
   secure/sql/dealerfai_restore_all.sql

6) Format: SQL (default).

7) Click Go and wait for the import to finish.

8) If you see any errors, copy the exact error message and share it.

Notes:
- This file includes the full backup plus all schema updates in date order.
- If you already imported some tables, you may need to drop the database and re-import.
