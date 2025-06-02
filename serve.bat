@echo off
echo Starting Laravel server at 0.0.0.0:8000...
start php artisan serve --host=0.0.0.0 --port=8000

echo Running Laravel scheduler every 60 seconds...
:loop
php artisan notify:tasks
timeout /t 60
goto loop
