# Billflow

## Live server deployment

`.env`-এ database ও SMTP password থাকে, তাই এটি Git-এ upload হয় না। Live server-এর project root-এ `.env.example` কপি করে `.env` নামে নতুন file তৈরি করুন এবং hosting provider-এর MySQL তথ্য বসান:

```dotenv
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=hosting_database_name
DB_USERNAME=hosting_database_user
DB_PASSWORD=hosting_database_password
```

SSH/Terminal থাকলে project root-এ চালান:

```bash
cp .env.example .env
composer install --no-dev --optimize-autoloader
```

Terminal না থাকলে cPanel File Manager দিয়ে `.env.example` কপি/rename করে `.env` বানান। Local project-এর সম্পূর্ণ `vendor/` folder-ও server-এ upload করুন। PHP-তে `pdo_mysql`, `mbstring`, `openssl`, `sodium` এবং `gd` extension চালু থাকতে হবে। MySQL database ও user আগে তৈরি করে user-কে database-এর সব প্রয়োজনীয় permission দিন। প্রথম সফল request-এ application প্রয়োজনীয় table তৈরি করবে।

Deployment-এর পরে `.env`, `vendor/autoload.php` এবং `assets/uploads/` আছে কি না যাচাই করুন। Database connection ব্যর্থ হলে application এখন raw HTTP 500 না দেখিয়ে setup নির্দেশনা দেখাবে; আসল connection error hosting error log-এ থাকবে।

PHP 8.3 + MySQL দিয়ে তৈরি ইনভয়েস ও কালেকশন অ্যাপ।

## চালু করুন

Laragon-এ এই ফোল্ডারটি site root হিসেবে খুলুন। `.env.example` কপি করে `.env` বানিয়ে MySQL host, database, username ও password দিন। MySQL-এ নির্ধারিত database আগে তৈরি থাকতে হবে; প্রথম request-এ প্রয়োজনীয় table স্বয়ংক্রিয়ভাবে তৈরি হবে। ডিফল্ট Super Admin ইমেইল `me@kbashar.com`; দেয়া bcrypt hash-টি ডেটাবেসে একবার সংরক্ষণ করা হয়। লগইনের জন্য সেই hash-এর **মূল পাসওয়ার্ড** লিখতে হবে, hash string নয়।

## SQLite থেকে MySQL migration

আগের `storage/invoice.sqlite`-এর সব data, ID এবং relation MySQL-এ নিতে প্রথমে `.env`-এ MySQL connection ঠিক করে চালান:

```powershell
& 'D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' migrate_sqlite_to_mysql.php
```

Target MySQL database-এ আগে থেকেই business data থাকলে script থেমে যাবে। নিশ্চিতভাবে সেটি মুছে SQLite data দিয়ে প্রতিস্থাপন করতে `--fresh` দিন। Migration সফল হওয়ার আগে পুরোনো SQLite file মুছবেন না।

PHP built-in server দিয়ে চালাতে:

```powershell
& 'D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -S 127.0.0.1:8000 router.php
```

তারপর `http://127.0.0.1:8000` খুলুন।

## Recurring invoice

রিকারিং ইনভয়েস তৈরি করলে প্রথম ইনভয়েস সঙ্গে সঙ্গে সেভ হয়; পরের বিলিং তারিখ ও আইটেমের snapshot schedule-এ থাকে। অ্যাপ খোলা হলে due schedule-এর ইনভয়েস তৈরি হয়। অ্যাপ না খুললেও নিয়মিত তৈরি করতে Windows Task Scheduler-এ দিনে একবার নিচের কমান্ড চালানোর task দিন:

```powershell
& 'D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' 'D:\laragon\www\invoice\cron.php'
```

## ক্লায়েন্ট অ্যাকাউন্ট

ইনভয়েসে দেয়া মোবাইল নম্বর normalize করে ইউনিক `clients` রেকর্ড তৈরি হয়। বিদ্যমান নম্বর হলে সেই অ্যাকাউন্টের সঙ্গে ইনভয়েস যুক্ত হয়। Client portal এখনও নেই, তাই client password ফাঁকা রাখা হয়; পরের ধাপে যাচাই করা activation flow যোগ করে ক্লায়েন্টকে login ও online payment দেয়া যাবে। এখনকার পেমেন্ট কালেকশন অ্যাডমিন হাতে রেকর্ড করেন।

নতুন ইনভয়েসে `Company Name` ঐচ্ছিক। এটি ক্লায়েন্ট অ্যাকাউন্টে রাখা হয় এবং প্রতিটি ইনভয়েসে আলাদা snapshot হিসেবে সেভ হয়।

ইনভয়েস বিস্তারিত পেজের **এডিট করুন** বোতাম থেকে বিলিং ক্লায়েন্ট, তারিখ, সার্ভিস/আইটেম, পরিমাণ, দর ও নোট বদলানো যায়। ইনভয়েস নম্বর ও আগের পেমেন্ট অক্ষত থাকে। নতুন মোট আগে কালেকশন করা টাকার কম হলে পরিবর্তন সেভ হয় না। Recurring ইনভয়েস এডিট করলে শুধু সেই ইনভয়েস বদলায়; পরবর্তী schedule বদলায় না।

## ইনভয়েস কালেকশন

সাইডবারের **ইনভয়েস কালেকশন** থেকে সব বকেয়া ও আংশিক পরিশোধিত invoice দেখা যায়। Invoice নির্বাচন করে Partial Collection অথবা সম্পূর্ণ বকেয়ার Full Paid Collection নেওয়া যায়। বকেয়ার বেশি collection গ্রহণ করা হয় না এবং একই পেজে সাম্প্রতিক collection history দেখা যায়।

## Dashboard Settings

সাইডবারের **ড্যাশবোর্ড সেটিংস**-এ Basic Settings ও SMTP ট্যাব আছে। Basic Settings-এর Site Title সাইডবার, লগইন ও ইনভয়েসে; Slogan সাইডবার ও লগইনে; Mobile Number এবং Email ইনভয়েসের যোগাযোগ অংশে দেখানো হয়। SMTP Host, Port, Encryption, Username, Password, From Name ও From Email সেভ করলে নতুন এবং recurring invoice তৈরির পর client-কে PDF attachment-সহ email পাঠানো হয়। ব্যর্থ delivery database queue-তে থাকে এবং `cron.php` সর্বোচ্চ তিনবার retry করে। SMTP পাসওয়ার্ড এনক্রিপ্ট করার key `storage/smtp.key`-তে থাকে; ডেটাবেসের সঙ্গে এই ফাইলটিও নিরাপদে backup রাখতে হবে।

Basic Settings থেকে Logo (PNG, JPG, WebP; সর্বোচ্চ ৩ MB) এবং Favicon (PNG, WebP, ICO; সর্বোচ্চ ১ MB) আপলোড করা যায়। ফাইল বাছার সঙ্গে সঙ্গে preview দেখা যায়; সেভ করার পর ছবি `assets/uploads/`-এ থাকে। Logo সাইডবার, লগইন ও ইনভয়েসে এবং Favicon ব্রাউজার ট্যাবে দেখা যায়। ইনভয়েসের জন্য অফিসের ঠিকানা ও Website-ও এখানে দেয়া যায়। Backup-এ `assets/uploads/` ফোল্ডারও রাখতে হবে।

## Payment Method ও PDF ভিউ

সাইডবারের **Payment Method** থেকে ব্যাংক, MFS, কার্ড বা অন্য পেমেন্ট মেথড যোগ এবং এডিট করা যায়। অ্যাকাউন্টের নাম ও নম্বর, মোবাইল নম্বর, শাখা/রাউটিং তথ্য, নির্দেশনা এবং QR ছবি (PNG, JPG, WebP; সর্বোচ্চ ৩ MB) রাখা যায়। মেথড নিষ্ক্রিয় করা যায়; আগে তৈরি ইনভয়েসে নির্ধারিত মেথড দেখা যায়। ইনভয়েসে কোনো মেথড নির্দিষ্ট না থাকলে বিস্তারিত ও প্রিন্ট/PDF ভিউতে সব সক্রিয় মেথড দেখায়। নতুন বা এডিট করা ইনভয়েসে একটি নির্দিষ্ট মেথড বেছে নিলে শুধু সেটিই দেখায়। রিকারিং ইনভয়েসের পরের চক্রেও নির্বাচিত মেথড থাকে।

ইনভয়েস তালিকা বা বিস্তারিত পেজে **PDF View** চাপলে Hind Siliguri বাংলা ফন্ট, পেমেন্ট মেথডের তথ্য ও ছোট QR-সহ এক পৃষ্ঠার A4 PDF নতুন ট্যাবে খুলে যায়। PDF তৈরিতে mPDF ব্যবহার করা হয়েছে; নতুন ইনস্টলেশনে `composer install` চালিয়ে dependency ইনস্টল করতে হবে। Composer-এর প্যাকেজ ফাইলগুলো এই প্রকল্পের `vendor/` ফোল্ডারেও আছে।

## ডেটা ও নিরাপত্তা

- Admin login, password hashing ও CSRF token আছে।
- টাকা পয়সায় integer হিসেবে সংরক্ষণ করা হয়। অতিরিক্ত কালেকশন বন্ধ করা আছে।
- `storage/.htaccess` Apache-তে ডেটাবেস ডাউনলোড বন্ধ করে। Built-in server-এর `router.php`-ও এটি বন্ধ করে।
- লাইভ deployment-এ HTTPS, নিয়মিত MySQL backup, এবং প্রকৃত payment gateway যোগ করতে হবে।
