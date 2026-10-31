// Compatibility wrapper: application review state stays in the page; WeChat credit hints use the shared floating queue.
Component({
 options:{styleIsolation:'apply-shared'},
 properties:{audience:{type:String,value:'user'},compact:{type:Boolean,value:true},active:{type:Boolean,value:true},reviewPending:{type:Boolean,value:false}}
})
