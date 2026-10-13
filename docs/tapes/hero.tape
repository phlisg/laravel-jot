# Live events: jot:query --follow while the workbench demos send requests.
# Render from the repository root: vhs docs/tapes/hero.tape

Output docs/images/hero.gif

Set Shell bash
Set Theme "Catppuccin Mocha"
Set FontSize 14
Set Width 1240
Set Height 250
Set Padding 18
Set Framerate 15
Set TypingSpeed 45ms

# `php artisan` runs the workbench app; demos start once the command is typed.
Hide
Type `export PS1='\[\e[1;35m\]$\[\e[0m\] '; set +m; php() { if [ "$1" = artisan ]; then shift; command php vendor/bin/testbench "$@"; else command php "$@"; fi; }`
Enter
Type `cd vendor/orchestra/testbench-core/laravel/storage/logs && rm -f jot-20*.log && touch jot-$(date -u +%F).log && cd - >/dev/null`
Enter
Type `(sleep 3.5; for s in booking login-failed error job-sync; do php artisan jot:demo $s >/dev/null 2>&1; sleep 0.3; done) & clear`
Enter
Sleep 500ms
Show

Type "php artisan jot:query --follow"
Sleep 400ms
Enter
Sleep 7500ms
Hide
Ctrl+C
