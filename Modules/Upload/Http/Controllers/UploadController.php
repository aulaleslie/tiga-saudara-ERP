<?php

namespace Modules\Upload\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Modules\Upload\Entities\Upload;
use Symfony\Component\HttpFoundation\Response;

class UploadController extends Controller
{

    public function filepondUpload(Request $request) {
        $request->validate([
            'image' => 'required|image|mimes:png,jpeg,jpg'
        ]);

        if ($request->hasFile('image')) {
            $uploaded_file = $request->file('image');
            $filename = now()->timestamp . '.' . $uploaded_file->getClientOriginalExtension();
            $folder = uniqid() . '-' . now()->timestamp;

            $file = Image::make($uploaded_file)->encode($uploaded_file->getClientOriginalExtension());

            Storage::put('temp/' . $folder . '/' . $filename, $file);

            Upload::create([
                'folder'   => $folder,
                'filename' => $filename
            ]);

            return $folder;
        }

        return false;
    }

    public function filepondDelete(Request $request) {
        $upload = Upload::where('folder', $request->getContent())->first();
        if ($upload) {
            Storage::deleteDirectory('temp/' . $upload->folder);
            $upload->delete();
        }
        return response(null);
    }

    // --- DROPZONE (updated) ---
    public function dropzoneUpload(Request $request)
    {
        // Server-side validation to match your client rules
        $request->validate([
            'file' => 'required|image|mimes:jpg,jpeg,png|max:1024', // 1MB
        ]);

        $file = $request->file('file');

        // Generate a truly unique filename to avoid collisions on re-uploads
        $ext  = $file->getClientOriginalExtension();
        $name = Str::uuid()->toString() . '.' . $ext;

        // Optional: normalize/resize to your tooltip spec (400x400). Comment out if you want original.
        // Keeps default disk (storage/app/...), which matches your ProductController (Storage::path(...)).
        $image = Image::make($file)->fit(400, 400, function ($c) { $c->upsize(); });
        Storage::put('temp/dropzone/' . $name, (string) $image->encode($ext));

        // If you prefer *no* processing, replace the 2 lines above with:
        // Storage::putFileAs('temp/dropzone', $file, $name);

        return response()->json([
            'name'          => $name,                          // this is what goes in document[]
            'original_name' => $file->getClientOriginalName(), // for UI only
        ]);
    }

    public function dropzoneDelete(Request $request)
    {
        $request->validate([
            'file_name' => 'required|string',
        ]);

        Storage::delete('temp/dropzone/' . $request->string('file_name'));

        return response()->json($request->file_name, 200);
    }

    public function dropzoneTemp(string $name)
    {
        $path = 'temp/dropzone/' . $name;

        if (!Storage::exists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->file(Storage::path($path));
    }
}
