wp-integrity-check
------------------

wp-integrity-check help system administrator to discover WordPress websites that were altered or hacked due to missconfiguration, password leak, succesfull bruteforce, weak plugins and other reason.  hen we are managing a servers with hundreds of WordPress installation, it could be difficult to get a clear view of what is going on. 

This software is a tools to help us find WordPress integrity problems.

* Description: Check integrity of all WordPress installation in a specific path.
* Require PHP 5.4 or more
* Usage:

  * `./wp-integrity-check.php [--depth DEPTH] [--] [<path>]`

Check integrity of all WordPress installation in a specific path.

##### Arguments:

**path:**

* Name: path
* Is required: yes
* Description: The path of containing WordPress installation that would be scanned.

##### Options:

**depth:**

* Name: `--depth`
* Description: The maximum depth to scan for WordPress installation
* Default: `NULL`

**help:**

* Name: `--help`
* Shortcut: `-h`
* Description: Display help message

**version:**

* Name: `--version`
* Shortcut: `-V`
* Description: Display this application version
