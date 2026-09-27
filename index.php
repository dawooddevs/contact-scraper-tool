<?php

// Fallback for hosts where the root .htaccess rewrite is not active:
// send visitors to the public/ folder, which contains the app.
header('Location: public/', true, 302);
