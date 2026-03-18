protected $routeMiddleware = [
    // existing...
    'firebase.auth' => \App\Http\Middleware\FirebaseAuthMiddleware::class,
];