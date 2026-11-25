<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Spatie\TranslationLoader\LanguageLine;

class TranslationsController extends Controller
{
    public function list()
    {
        return LanguageLine::orderBy('id')->paginate(10);
    }

    public function create(Request $request, Application $app)
    {
        $data = $request->validate([
            'group' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_-]+\z/D'],
            'key' => ['required', 'string', 'max:160', 'regex:/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/D'],
            'text' => 'required|string|max:4096',
        ]);

        $line = new LanguageLine();
        $line->group = $data['group'];
        $line->key = $data['key'];
        $line->setTranslation($app->getLocale(), $data['text']);
        $line->save();
        $request->session()->put('success', true);

        return redirect('/admin/i18n');
    }

    public function update(Request $request, Application $app, LanguageLine $line)
    {
        $data = $request->validate(['text' => 'required|string|max:4096']);
        $line->setTranslation($app->getLocale(), $data['text']);
        $line->save();

        return json(trans('admin.i18n.updated'), 0);
    }

    public function delete(LanguageLine $line)
    {
        $line->delete();

        return json(trans('admin.i18n.deleted'), 0);
    }
}
