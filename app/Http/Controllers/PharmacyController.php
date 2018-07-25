pharmacies<?php

namespace App\Http\Controllers;

use App\Jobs\PutPharmaciesToBlockchain;
use App\Jobs\PutpharmacyFilesToBlockchain;
use App\Jobs\PutpharmacyNoRevisions;
use App\Jobs\PutPharmacyPropertiesToBlockchain;
use App\Models\EtherAccounts;
use App\Models\Pharmacy;
use App\Models\PharmacyFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PharmacyController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {

        $pharmacies = Pharmacy::latest()->get();

        return view('pharm.index', compact('pharmacies'))->render();

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {

        return view('pharm.create')->render();

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

    protected function _store(Request $request)
    {

        $pharm = new Pharmacy();
        $pharm->uuid = auth()->user()->uuid;
        $pharm->name = array_get($request->input('pharm'), 'name');
        $pharm->address = array_get($request->input('pharm'), 'address');

        /* google map api */
        if(is_array($gApi = $request->input('googleMapAPI',null))){
            $pharm->gm_lat         = $gApi['lat'];
            $pharm->gm_lon         = $gApi['lon'];
            $pharm->gm_place_id    = $gApi['placeID'];
        }

        /* eth auto generated account */
        $ethAccount = new EtherAccounts();
        $ethAccount->save();
        $pharm->eth_address = $ethAccount;

        $fileNames = $request->input('docsName',[]);
        foreach (($files = $request->files->get('docs', [])) as $idxFile => $file) {
            $fileName = trim(array_get($fileNames, $idxFile, ''));
            $pharm->newUpload($file, $fileName);

        }

        $props = $request->input('pharm_props', []);
        if (!empty($props)) {

            foreach ($props['key'] as $k => $optionName) {

                if (empty($optionName)) {
                    continue;
                }

                $optionValue = $props['value'][$k];

                $pharm->newProperty($optionName, $optionValue);

            }

        }

        $pharm->save();

        dispatch(new PutPharmacyNoRevisions($pharm));

        return response()->redirectTo(route('pharm.show', $pharm));

    }

    /**
     * Display the specified resource.
     *
     * @param  Pharmacy $pharmacy
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Pharmacy $pharm)
    {

        return view('pharm.show', ['pharmacy' => $pharm])->render();

    }

    public function download(Request $request, $pharm, $file){

        $filePath = storage_path(
                pharmacy::storageLocation($pharm, false)
                . $file
            );

        $file = pharmacyFile::where('eth_address',$pharm)->where('sha512',$file)->get();

        if(!$file){
            abort(404);
        }
        $file = $file[0];

        if(\Illuminate\Support\Facades\File::exists($filePath)){

            return response()->download(
                $filePath,
                implode('.',[$file->filename , $file->extension])
            );

        }else{

            abort(404);

        }

    }

}
