<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Laravel\Firebase\Facades\Firebase;

class ServiceTagController extends Controller
{
    protected $database;

    public function __construct()
    {
        $this->database = Firebase::firestore()->database();
    }

    public function index()
    {
        $documents = $this->database
            ->collection('serviceTags')
            ->where('isActive', '=', true)
            ->documents();

        $tags = [];
        foreach ($documents as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                $tags[] = [
                    'id'    => $doc->id(),
                    'label' => $data['label'],
                    'slug'  => $data['slug'],
                ];
            }
        }

        return response()->json([
            'message' => 'Service tags retrieved successfully',
            'data'    => $tags,
        ], 200);
    }
}
