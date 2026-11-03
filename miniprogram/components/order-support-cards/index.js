Component({
 options:{styleIsolation:'apply-shared'},
 properties:{items:{type:Array,value:[]}},
 methods:{
  select(event){
   const key=event.currentTarget.dataset.key
   if(this.properties.items.some(item=>item.key===key))this.triggerEvent('action',{key})
  }
 }
})
