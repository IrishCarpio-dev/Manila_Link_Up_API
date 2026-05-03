<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Laravel\Firebase\Facades\Firebase;

class ProfilePhotoController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = Firebase::firestore()->database();
    }

    public function show(Request $request)
    {
        validator($request->all(), [
            'uid' => ['required', 'string'],
        ])->validate();

        $snap = $this->database->collection('profilePhotos')->document($request->uid)->snapshot();

        return response()->json([
            'data' => $snap->exists() ? ($snap->data()['base64'] ?? null) : null,
        ], 200);
    }
}
