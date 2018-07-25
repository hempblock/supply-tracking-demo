<?php

namespace App\Http\Controllers;

use App\Jobs\PutFarmerNoRevisions;
use App\Models\EtherAccounts;
use App\Models\Sponsor;
use App\Models\FarmerFile;
use Illuminate\Support\Facades\DB;
use \Illuminate\Http\Request;
use \Illuminate\Routing\Controller;

class SponsorController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $sponsors = auth()->user()->sponsors()->latest()->get();

        return view('sponsor.index', compact('sponsors'))
            ->with('dashboard_params', array('title'=>'Sponsors', 'active_li_main'=>'sponsors'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('sponsor.create');
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

    protected function _store(Request $request, Sponsor $sponsor = null, EtherAccounts $ethAccount = null)
    {
        if($doInsert = (is_null($sponsor))){
            $sponsor = new Sponsor();
            $sponsor->uuid = auth()->user()->uuid;
        }

        /* separated accounts table */
        if(empty($ethAccount) and $doInsert){
            // auto generated account
            $ethAccount = new EtherAccounts();
            $ethAccount->save();
            $sponsor->eth_address = $ethAccount;
        }
        /* main sponsor record */
        $sponsor->firstname  = array_get($request->input('sponsor'), 'firstname');
        $sponsor->lastname   = array_get($request->input('sponsor'), 'lastname');
        $sponsor->email      = array_get($request->input('sponsor'), 'email');
        $sponsor->address    = array_get($request->input('sponsor'), 'address');

        /* google map api */
        if(is_array($gApi = $request->input('googleMapAPI',null))){
            $sponsor->gm_lat         = $gApi['lat'];
            $sponsor->gm_lon         = $gApi['lon'];
            $sponsor->gm_place_id    = $gApi['placeID'];
        }

        /* file manager (existing files)*/
        $existingFiles = $request->input('existingFile', []);
        foreach ($existingFiles as $fileID => $fileOptions){
            if ($fileOptions['delete'] == 'y') {
                $sponsor->setForDeletion($fileID);
            } else {
                $sponsor->setForRenaming($fileID, $fileOptions['name']);
            }
        }

        /* file manager (new files)*/
        $fileNames = $request->input('docsName',[]);
        foreach (($files = $request->files->get('docs', [])) as $idxFile => $file) {
            $fileName = trim(array_get($fileNames, $idxFile, ''));
            $sponsor->newUpload($file, $fileName);

        }

        $props = $request->input('farmer_props', []);
        if (!empty($props)) {
            foreach ($props['key'] as $k => $optionName) {
                if (empty($optionName))
                    continue;

                $optionValue = $props['value'][$k];
                $sponsor->newProperty($optionName, $optionValue);
            }
        }

        $sponsor->save();
        dispatch(new PutSponsorNoRevisions($sponsor));

        return response()->redirectTo(route('sponsor.show', $sponsor));

    }

    /**
     * Display the specified resource.
     *
     * @param  Sponsor $sponsor
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Sponsor $sponsor)
    {
        return view('sponsor.show', compact('sponsor'));
    }

    /**
     * @param Request $request
     * @param Sponsor  $sponsor
     *
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function update(Request $request, Sponsor $sponsor)
    {
        try {
            DB::beginTransaction();
            $this->_store($request, $sponsor);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return redirect()->route('sponsor.show',$sponsor);
    }

    public function hackUpdate(Request $request, Sponsor $sponsor) {
        $validatedData = $request->validate([
            'firstname' => 'required',
            'lastname' => 'required',
            'email' => 'required|email|unique:sponsors,email,'.$sponsor->id,
            'address' => 'required',
        ]);
        $sponsor->fill($validatedData);
        $sponsor->save();
        return redirect()
            ->route('sponsor.show', $sponsor)
            ->with('message', 'Data hacked!!!');
    }

    public function download($sponsor, $file){

        $filePath = storage_path(Sponsor::storageLocation($sponsor, false) . $file);

        $file = FarmerFile::where('eth_address',$sponsor)
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
     * @param  Sponsor $sponsor
     *
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, Sponsor $sponsor)
    {
        return view('sponsor.edit', compact('sponsor'));
    }

    public function checkDataIdentity(Sponsor $sponsor)
    {
        $dataFromDB = Sponsor::blockChainFormat($sponsor);
        if ($sponsor->tx_sponsor) {
            $tx = app('eth')->eth_getTransactionByHash($sponsor->tx_farm);
            $data = app('eth')->decodeData($tx['input'], '@setGrower', 'data');
            $dataFromBlockchain = $data['data']['arg_1_string'];
            return response()
                ->json(['equal' => compare_data($dataFromDB, $dataFromBlockchain)]);
        }
        return response()->json(['error' => __('Something goes wrong')]);
    }

}