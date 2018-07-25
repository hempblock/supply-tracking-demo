<?php

namespace App\Http\Controllers;

use App\Jobs\PutCertifierNoRevisions;
use App\Models\EtherAccounts;
use App\Models\Certifier;
use App\Models\CertifierFile;
use Illuminate\Support\Facades\DB;
use \Illuminate\Http\Request;
use \Illuminate\Routing\Controller;

class CertifierController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $certifiers = auth()->user()->certifiers()->latest()->get();

        return view('certifier.index', compact('certifiers'))
            ->with('dashboard_params', array('title'=>'Certifiers', 'active_li_main'=>'certifiers'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('certifier.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     * @throws \Exception
     */
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();
                $result = $this->_store($request);
            DB::commit();
        } catch (\Exception $e) {
                DB::rollBack();
            throw $e;
        }
        return $result;
    }

    protected function _store(Request $request, Certifier $certifier = null, EtherAccounts $ethAccount = null)
    {
        if($doInsert = (is_null($certifier))){
            $certifier = new Certifier();
            $certifier->uuid = auth()->user()->uuid;
        }

        /* separated accounts table */
        if(empty($ethAccount) and $doInsert){
            // auto generated account
            $ethAccount = new EtherAccounts();
            $ethAccount->save();
            $certifier->eth_address = $ethAccount;
        }
        /* main certifier record */
        $certifier->firstname  = array_get($request->input('certifier'), 'firstname');
        $certifier->lastname   = array_get($request->input('certifier'), 'lastname');
        $certifier->email      = array_get($request->input('certifier'), 'email');
        $certifier->address    = array_get($request->input('certifier'), 'address');

        /* google map api */
        if(is_array($gApi = $request->input('googleMapAPI',null))){
            $certifier->gm_lat         = $gApi['lat'];
            $certifier->gm_lon         = $gApi['lon'];
            $certifier->gm_place_id    = $gApi['placeID'];
        }

        /* file manager (existing files)*/
        $existingFiles = $request->input('existingFile', []);
        foreach ($existingFiles as $fileID => $fileOptions){
            if ($fileOptions['delete'] == 'y') {
                $certifier->setForDeletion($fileID);
            } else {
                $certifier->setForRenaming($fileID, $fileOptions['name']);
            }
        }

        /* file manager (new files)*/
        $fileNames = $request->input('docsName',[]);
        foreach (($files = $request->files->get('docs', [])) as $idxFile => $file) {
            $fileName = trim(array_get($fileNames, $idxFile, ''));
            $certifier->newUpload($file, $fileName);

        }

        $props = $request->input('certifier_props', []);
        if (!empty($props)) {
            foreach ($props['key'] as $k => $optionName) {
                if (empty($optionName))
                    continue;

                $optionValue = $props['value'][$k];
                $certifier->newProperty($optionName, $optionValue);
            }
        }

        $certifier->save();
        dispatch(new PutCertifierNoRevisions($certifier));

        return response()->redirectTo(route('certifier.show', $certifier));

    }

    /**
     * Display the specified resource.
     *
     * @param  Certifier $certifier
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Certifier $certifier)
    {
        return view('certifier.show', compact('certifier'));
    }

    /**
     * @param Request $request
     * @param Certifier  $certifier
     *
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function update(Request $request, Certifier $certifier)
    {
        try {
            DB::beginTransaction();
            $this->_store($request, $certifier);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return redirect()->route('certifier.show',$certifier);
    }

    public function hackUpdate(Request $request, Certifier $certifier) {
        $validatedData = $request->validate([
            'firstname' => 'required',
            'lastname' => 'required',
            'email' => 'required|email|unique:certifiers,email,'.$certifier->id,
            'address' => 'required',
        ]);
        $certifier->fill($validatedData);
        $certifier->save();
        return redirect()
            ->route('certifier.show', $certifier)
            ->with('message', 'Data hacked!!!');
    }

    public function download($certifier, $file){

        $filePath = storage_path(Certifier::storageLocation($certifier, false) . $file);

        $file = CertifierFile::where('eth_address',$certifier)
            ->where('sha512', $file)
            ->firstOrFail();

        if(\Illuminate\Support\Facades\File::exists($filePath)) {
            return response()->download(
                $filePath,
                implode('.',[$file->filename , $file->extension])
            );
        } else {
            abort(404);
        }
    }


    /**
     * This is a resource update which is a HTTP PUT METHOD.
     * @param  Request $request
     * @param  Certifier $certifier
     *
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, Certifier $certifier)
    {
        return view('certifier.edit', compact('certifier'));
    }

    public function checkDataIdentity(Certifier $certifier)
    {
        $dataFromDB = Certifier::blockChainFormat($certifier);
        if ($certifier->tx_farm) {
            $tx = app('eth')->eth_getTransactionByHash($certifier->tx_farm);
            $data = app('eth')->decodeData($tx['input'], '@setGrower', 'data');
            $dataFromBlockchain = $data['data']['arg_1_string'];
            return response()
                ->json(['equal' => compare_data($dataFromDB, $dataFromBlockchain)]);
        }
        return response()->json(['error' => __('Something goes wrong')]);
    }

}
